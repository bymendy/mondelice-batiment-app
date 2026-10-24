<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Activation/confirmation/désactivation du MFA (TOTP), et vérification du
 * code au moment de la connexion --- tâche 2.15.
 *
 * Flux d'activation : enable() génère un secret en attente de confirmation
 * (stocké mais two_factor_confirmed_at reste NULL) -> l'utilisateur scanne
 * le QR code (généré côté frontend à partir de otpauth_url) -> confirm()
 * vérifie un premier code réel avant d'activer officiellement et génère
 * les codes de récupération, affichés une seule fois.
 *
 * Flux de connexion (MFA actif) : AuthenticatedSessionController::store()
 * vérifie le mot de passe mais NE connecte PAS l'utilisateur ; il met son
 * id en attente en session ("two_factor_pending_user_id"). loginVerify()
 * ci-dessous vérifie le code puis termine la connexion (Auth::login()).
 */
class TwoFactorController extends Controller
{
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secret,
            ),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (empty($user->two_factor_secret)) {
            return response()->json([
                'message' => "Aucune activation MFA en cours. Appelez d'abord /api/2fa/enable.",
            ], 422);
        }

        $google2fa = new Google2FA();

        if (! $google2fa->verifyKey($user->two_factor_secret, $validated['code'])) {
            return response()->json(['message' => 'Code invalide.'], 422);
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(4).'-'.Str::random(4)))
            ->all();

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->save();

        return response()->json([
            'message' => 'MFA activé.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->requiresTwoFactor()) {
            return response()->json([
                'message' => 'Votre rôle impose le MFA : désactivation impossible.',
            ], 422);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json(['message' => 'MFA désactivé.']);
    }

    /**
     * Termine une connexion mise en attente par
     * AuthenticatedSessionController::store() --- accepte soit un code
     * TOTP courant, soit un code de récupération (usage unique, retiré
     * de la liste une fois consommé).
     *
     * Route PUBLIQUE (pas de middleware auth:sanctum) : à ce stade
     * l'utilisateur n'est volontairement PAS encore connecté. La session
     * existe déjà (cookie posé dès /sanctum/csrf-cookie ou /api/login),
     * c'est elle qui porte l'identifiant en attente, pas Sanctum.
     */
    public function loginVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $userId = $request->session()->get('two_factor_pending_user_id');

        if (! $userId) {
            return response()->json([
                'message' => 'Aucune connexion en attente de vérification MFA.',
            ], 422);
        }

        // Anti brute-force sur le challenge MFA lui-même : un code à 6
        // chiffres est un espace de recherche bien plus petit qu'un mot
        // de passe, donc une limite au moins aussi stricte que le login.
        $throttleKey = 'two-factor-login:'.$userId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Trop de tentatives. Réessayez dans {$seconds} secondes.",
            ], 429);
        }

        $user = User::find($userId);

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget('two_factor_pending_user_id');

            return response()->json(['message' => 'Session MFA invalide.'], 422);
        }

        $google2fa = new Google2FA();
        $validCode = $google2fa->verifyKey($user->two_factor_secret, $validated['code']);
        $usedRecoveryCode = false;

        if (! $validCode) {
            $recoveryCodes = $user->two_factor_recovery_codes ?? [];
            $submitted = Str::upper($validated['code']);
            $matchIndex = array_search($submitted, array_map('strtoupper', $recoveryCodes), true);

            if ($matchIndex !== false) {
                $usedRecoveryCode = true;
                unset($recoveryCodes[$matchIndex]);
                $user->two_factor_recovery_codes = array_values($recoveryCodes);
                $user->save();
            }
        }

        if (! $validCode && ! $usedRecoveryCode) {
            RateLimiter::hit($throttleKey);

            return response()->json(['message' => 'Code invalide.'], 422);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->forget('two_factor_pending_user_id');

        // Initialise le tenant dès ce point de la requête : le middleware IdentifyTenantByUser
        // s'est exécuté avant l'authentification et n'avait donc aucun tenant actif. Sans cet
        // appel, tenant_id() renverrait null pour la suite de la requête (notamment l'entrée
        // activity_log créée par le listener de connexion). Corrige le point ouvert "tenant_id
        // du journal non vérifié en conditions réelles" (Mondelice_Phase2_Backend.tex, §6).
        //
        // IMPORTANT : doit s'exécuter AVANT Auth::login(), pas après --- Auth::login() déclenche
        // l'événement Login de façon synchrone, qui écrit dans activity_log immédiatement.
        // Initialiser le tenant après coup arrive trop tard pour cette écriture.
        tenancy()->initialize($user->tenant_id);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => $user,
            'recovery_code_used' => $usedRecoveryCode,
        ]);
    }
}
