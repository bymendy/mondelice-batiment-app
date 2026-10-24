<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Gère la connexion et la déconnexion --- authentification par session
 * (mode SPA, voir Mondelice_Phase2_Backend.tex, décision A).
 *
 * Tâche 2.15 (MFA) : la connexion se fait en un ou deux temps selon que
 * l'utilisateur a activé le MFA. Identifiants valides + MFA inactif :
 * connexion immédiate, comportement inchangé depuis la tâche 2.4.
 * Identifiants valides + MFA actif : AUCUNE session n'est ouverte ici ---
 * seul l'identifiant utilisateur est mis en attente en session, et c'est
 * TwoFactorController::loginVerify() qui termine la connexion après
 * vérification du code (voir routes/api.php, groupe "2fa").
 *
 * @see \App\Http\Requests\Auth\LoginRequest Validation et limitation des tentatives.
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Vérifie les identifiants, puis connecte directement (MFA inactif)
     * ou met la connexion en attente d'un code MFA (MFA actif).
     *
     * @param LoginRequest $request Les identifiants soumis, avec limitation
     *                               anti brute-force (voir authenticate()).
     * @return JsonResponse L'utilisateur connecté, ou un indicateur
     *                      "two_factor_required" si un code MFA est attendu.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $user = User::where('email', $request->string('email'))->first();

        if ($user->hasTwoFactorEnabled()) {
            // Pas de session complète : l'identité est vérifiée (mot de
            // passe correct), mais la connexion n'est pas terminée tant
            // que le code MFA n'est pas validé.
            $request->session()->put('two_factor_pending_user_id', $user->id);

            return response()->json([
                'two_factor_required' => true,
            ]);
        }

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

        // Régénère l'identifiant de session après connexion : empêche la
        // fixation de session (même principe qu'à l'inscription).
        $request->session()->regenerate();

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * Déconnecte l'utilisateur et invalide sa session.
     *
     * @param Request $request La requête HTTP entrante.
     * @return JsonResponse Réponse vide (204 No Content), confirmant la déconnexion.
     */
    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }
}
