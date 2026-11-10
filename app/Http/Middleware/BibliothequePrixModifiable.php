<?php

namespace App\Http\Middleware;

use App\Models\Parametre;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique le reglage "le Commercial peut modifier la Bibliotheque de prix"
 * aux actions d'ecriture (creation, modification).
 *
 * Depuis le 10 novembre 2026 : le reglage est DESACTIVE par defaut (le
 * Commercial consulte seulement) et ne concerne que le Commercial. Un
 * utilisateur qui a aussi le role Direction ou Conducteur travaux n'est jamais
 * bloque : son droit d'ecriture ne vient pas du role Commercial. La
 * consultation n'est jamais bloquee (necessaire pour chiffrer un devis).
 */
class BibliothequePrixModifiable
{
    /**
     * @param Request $request
     * @param Closure $next
     * @return Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403 si le
     *         Commercial n'a pas ce droit dans son entreprise.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        $droitVientDuCommercial = $utilisateur !== null
            && $utilisateur->hasRole('Commercial')
            && ! $utilisateur->hasAnyRole(['Direction', 'Conducteur travaux']);

        if ($droitVientDuCommercial && ! Parametre::lire('commercial_peut_modifier_bibliotheque')) {
            abort(403, 'La modification de la bibliothèque de prix n\'est pas activée pour le Commercial : la Direction ou l\'Administratif peuvent l\'activer dans les réglages.');
        }

        return $next($request);
    }
}
