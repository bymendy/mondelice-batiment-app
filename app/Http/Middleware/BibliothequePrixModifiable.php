<?php

namespace App\Http\Middleware;

use App\Models\Parametre;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique l'interrupteur "le Commercial peut modifier la Bibliotheque de
 * prix" aux actions d'ecriture (creation, modification, archivage).
 *
 * Direction et Administratif ne sont jamais concernes par le verrou : ce
 * sont eux qui le posent. Tout autre utilisateur qui a la permission
 * d'ecriture (en pratique le Commercial) est bloque quand le reglage est
 * desactive. La consultation n'est jamais bloquee (necessaire pour chiffrer
 * un devis).
 */
class BibliothequePrixModifiable
{
    /**
     * @param Request $request
     * @param Closure $next
     * @return Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403 si le
     *         verrou est actif pour cet utilisateur.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $estGestionnaire = $request->user()?->hasAnyRole(['Direction', 'Administratif']);

        if (! $estGestionnaire && ! Parametre::lire('commercial_peut_modifier_bibliotheque')) {
            abort(403, 'La modification de la bibliothèque de prix est verrouillée par la Direction ou l\'Administratif.');
        }

        return $next($request);
    }
}
