<?php

namespace App\Http\Controllers;

use App\Http\Requests\LigneDevisDepuisArticleRequest;
use App\Models\ArticlePrix;
use App\Models\Devis;
use App\Services\Devis\AccesDevis;
use App\Services\Devis\CopieArticleDansDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * Ajout d'une ligne de devis depuis la bibliotheque de prix (copie figee).
 */
class LigneDevisArticleController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:devis.modifier'),
            new Middleware('can:bibliotheque.consulter'),
        ];
    }

    /**
     * @param LigneDevisDepuisArticleRequest $request
     * @param int $devis
     * @param CopieArticleDansDevis $copie
     * @return JsonResponse
     *
     * @throws ValidationException Si l'article est introuvable, archive ou sans prix calculable.
     */
    public function store(LigneDevisDepuisArticleRequest $request, int $devis, CopieArticleDansDevis $copie): JsonResponse
    {
        $utilisateur = $request->user();
        $fiche = AccesDevis::visibles($utilisateur)->findOrFail($devis);
        AccesDevis::exigerModifiable($fiche);

        $article = ArticlePrix::query()
            ->visiblesPour($utilisateur)
            ->where('actif', true)
            ->find($request->integer('article_prix_id'));

        if ($article === null) {
            throw ValidationException::withMessages([
                'article_prix_id' => 'Cet article est introuvable ou archivé.',
            ]);
        }

        $ligne = $copie->copier(
            $fiche,
            $article,
            (float) $request->input('quantite'),
            $request->filled('lot_id') ? $request->integer('lot_id') : null,
            $request->filled('emplacement_id') ? $request->integer('emplacement_id') : null
        );

        $fiche->recalculerTotaux();

        activity()
            ->performedOn($fiche)
            ->causedBy($utilisateur)
            ->event('updated')
            ->withProperties(['article_prix_id' => $article->getKey(), 'reference' => $article->reference])
            ->log(sprintf('Ligne « %s » ajoutée depuis la bibliothèque (%s).', $ligne->libelle, $article->reference));

        return response()->json(
            array_merge(
                \App\Services\Devis\PresentationDevis::ligne($ligne->refresh()->load('composants'), $utilisateur),
                ['totaux_devis' => $this->totaux($fiche)]
            ),
            201
        );
    }

    /**
     * @param Devis $devis
     * @return array<string, mixed>
     */
    private function totaux(Devis $devis): array
    {
        return [
            'total_ht' => $devis->total_ht,
            'total_tva' => $devis->total_tva,
            'total_ttc' => $devis->total_ttc,
        ];
    }
}
