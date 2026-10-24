<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Database\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifie le tenant courant à partir de l'utilisateur authentifié.
 *
 * Contrairement au mode multi-base de stancl/tenancy (qui identifie le
 * tenant via un sous-domaine ou un en-tête HTTP), cette application utilise
 * le mode single-database : toutes les entreprises clientes partagent la
 * même base PostgreSQL, et chaque ligne est filtrée par tenant_id.
 *
 * Ce middleware ne fait qu'une chose : si un utilisateur est connecté et
 * possède un tenant_id, il initialise le contexte de tenancy pour que les
 * modèles utilisant le trait BelongsToTenant filtrent automatiquement
 * leurs requêtes sur ce tenant.
 */
class IdentifyTenantByUser
{
    /**
     * Initialise le tenant courant avant de poursuivre la requête.
     *
     * @param  Request  $request  La requête HTTP entrante.
     * @param  Closure(Request): Response  $next  Le prochain middleware de la pile.
     * @return Response
     *
     * @throws \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException
     *         Si le tenant trouvé est invalide (ne devrait pas arriver ici
     *         puisqu'on vérifie son existence avant d'initialiser).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id && ! tenancy()->initialized) {
            $tenant = Tenant::find($user->tenant_id);

            if ($tenant) {
                tenancy()->initialize($tenant);
            }
        }

        return $next($request);
    }
}
