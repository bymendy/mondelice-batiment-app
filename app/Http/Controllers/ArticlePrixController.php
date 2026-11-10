<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreArticlePrixRequest;
use App\Http\Requests\UpdateArticlePrixRequest;
use App\Models\ArticlePrix;
use App\Services\Bibliotheque\PresentateurArticlePrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * CRUD de la Bibliotheque de prix (taches 3.3 et 3.4, lot 3).
 *
 * Catalogue de l'entreprise, plus les articles de reference partages (en
 * lecture seule). L'autorisation se fait par permission Spatie
 * "bibliotheque.*" via le middleware natif "can:".
 *
 * Lot 3 : tous les articles crees ont la portee `entreprise`. Les versions
 * privees et la resolution « privee > entreprise > reference » arrivent au
 * lot 4. Le sous-detail (composants) n'a pas encore de route.
 */
class ArticlePrixController extends Controller implements HasMiddleware
{
    /**
     * Permission requise par action.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:bibliotheque.consulter', only: ['index', 'show']),
            new Middleware('can:bibliotheque.personnaliser', only: ['store', 'update']),
            new Middleware('can:bibliotheque.supprimer', only: ['destroy']),
            new Middleware(\App\Http\Middleware\BibliothequePrixModifiable::class, only: ['store', 'update']),
        ];
    }

    /**
     * Liste paginee des articles visibles (entreprise courante + references).
     *
     * Query params :
     *  - actif=0|1          filtre sur le statut actif (defaut : tous)
     *  - type_article=...   filtre sur le type (materiaux, main_oeuvre...)
     *  - categorie_id=...   filtre sur la categorie de l'arborescence
     *  - recherche=...      recherche texte sur reference/designation/fournisseur
     *  - par_page=...       taille de page (defaut 25)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = ArticlePrix::query()->with('composants');

        if ($request->filled('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        if ($request->filled('type_article')) {
            $query->where('type_article', $request->string('type_article'));
        }

        if ($request->filled('categorie_id')) {
            $query->where('categorie_id', $request->integer('categorie_id'));
        }

        if ($request->filled('recherche')) {
            $terme = $request->string('recherche');
            $query->where(function ($q) use ($terme): void {
                $q->where('reference', 'ilike', "%{$terme}%")
                    ->orWhere('designation', 'ilike', "%{$terme}%")
                    ->orWhere('fournisseur', 'ilike', "%{$terme}%");
            });
        }

        $page = $query->orderBy('designation')->paginate($request->integer('par_page', 25));
        $presentateur = PresentateurArticlePrix::pour($request->user());
        $page->through(fn (ArticlePrix $article): array => $presentateur->presenter($article));

        return response()->json($page);
    }

    /**
     * Cree un nouvel article de l'entreprise (portee `entreprise`).
     *
     * @param StoreArticlePrixRequest $request
     * @return JsonResponse
     */
    public function store(StoreArticlePrixRequest $request): JsonResponse
    {
        $article = ArticlePrix::create($request->validated())->load('composants');

        return response()->json(PresentateurArticlePrix::pour($request->user())->presenter($article), 201);
    }

    /**
     * Affiche un article (de l'entreprise ou de reference).
     *
     * @param Request $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function show(Request $request, ArticlePrix $articlePrix): JsonResponse
    {
        $articlePrix->load('composants');

        return response()->json(PresentateurArticlePrix::pour($request->user())->presenter($articlePrix));
    }

    /**
     * Met a jour un article de l'entreprise. Un article de reference ne se
     * modifie pas (403).
     *
     * @param UpdateArticlePrixRequest $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function update(UpdateArticlePrixRequest $request, ArticlePrix $articlePrix): JsonResponse
    {
        abort_if($articlePrix->estReference(), 403, 'Un article de référence ne se modifie pas.');

        $articlePrix->update($request->validated());
        $articlePrix->load('composants');

        return response()->json(PresentateurArticlePrix::pour($request->user())->presenter($articlePrix));
    }

    /**
     * Archive l'article (soft delete) -- jamais de suppression definitive
     * tant qu'un devis peut encore le referencer. Un article de reference ne
     * s'archive pas (403).
     *
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function destroy(ArticlePrix $articlePrix): JsonResponse
    {
        abort_if($articlePrix->estReference(), 403, 'Un article de référence ne s\'archive pas.');

        $articlePrix->delete();

        return response()->json(null, 204);
    }
}
