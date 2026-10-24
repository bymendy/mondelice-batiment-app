<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tâche 2.15 : impose l'activation du MFA avant d'accéder à une route
 * protégée, pour les rôles où le MFA est obligatoire (Direction,
 * Administratif, Ressources Humaines --- CONTEXTE_SECURITE_RGPD_IA.md,
 * §1.2 point 1).
 *
 * Middleware de ROUTE (pas global) : s'attache explicitement derrière
 * "auth:sanctum" (qui garantit que $request->user() existe), sur les
 * routes métier qui doivent l'exiger --- jamais sur /api/2fa/enable ou
 * /api/2fa/confirm, qui doivent au contraire rester accessibles à
 * l'utilisateur tant qu'il n'a pas terminé son activation, sous peine de
 * blocage complet (lui-même ne pourrait alors plus jamais activer son MFA).
 */
class EnsureTwoFactorIsSetUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => "Votre rôle impose l'activation du MFA avant d'accéder à cette ressource.",
                'two_factor_setup_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
