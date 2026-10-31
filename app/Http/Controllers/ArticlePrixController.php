<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreArticlePrixRequest;
use App\Http\Requests\UpdateArticlePrixRequest;
use App\Models\ArticlePrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * CRUD de la Bibliotheque de prix (tache 3.3).
 *
 * Catalogue partage par tous les utilisateurs du tenant (pas de filtrage
 * par ligne) -- l'autorisation se fait par permission Spatie
 * "bibliotheque_prix.*" via le middleware natif "can:", ajoute lors de la
 * tache 3.4 (la route n'avait d'abord que auth:sanctum).
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
            new Middleware('can:bibliotheque_prix.voir', only: ['index', 'show']),
            new Middleware('can:bibliotheque_prix.creer', only: ['store']),
            new Middleware('can:bibliotheque_prix.modifier', only: ['update']),
            new Middleware('can:bibliotheque_prix.supprimer', only: ['destroy']),
            new Middleware(\App\Http\Middleware\BibliothequePrixModifiable::class, only: ['store', 'update', 'destroy']),
        ];
    }

    /**
     * Liste paginee des articles du tenant courant.
     *
     * Query params :
     *  - actif=0|1       filtre sur le statut actif (defaut : tous)
     *  - categorie=...   filtre sur la categorie
     *  - recherche=...   recherche texte sur reference/designation/fournisseur
     *  - par_page=...    taille de page (defaut 25)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = ArticlePrix::query();

        if ($request->filled('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        if ($request->filled('categorie')) {
            $query->where('categorie', $request->string('categorie'));
        }

        if ($request->filled('recherche')) {
            $terme = $request->string('recherche');
            $query->where(function ($q) use ($terme): void {
                $q->where('reference', 'ilike', "%{$terme}%")
                    ->orWhere('designation', 'ilike', "%{$terme}%")
                    ->orWhere('fournisseur', 'ilike', "%{$terme}%");
            });
        }

        return response()->json(
            $query->orderBy('designation')->paginate($request->integer('par_page', 25))
        );
    }

    /**
     * Cree un nouvel article.
     *
     * @param StoreArticlePrixRequest $request
     * @return JsonResponse
     */
    public function store(StoreArticlePrixRequest $request): JsonResponse
    {
        return response()->json(ArticlePrix::create($request->validated()), 201);
    }

    /**
     * Affiche un article.
     *
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function show(ArticlePrix $articlePrix): JsonResponse
    {
        return response()->json($articlePrix);
    }

    /**
     * Met a jour un article.
     *
     * @param UpdateArticlePrixRequest $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function update(UpdateArticlePrixRequest $request, ArticlePrix $articlePrix): JsonResponse
    {
        $articlePrix->update($request->validated());

        return response()->json($articlePrix);
    }

    /**
     * Desactive l'article (soft delete) -- jamais de suppression
     * definitive tant qu'un devis peut encore le referencer.
     *
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function destroy(ArticlePrix $articlePrix): JsonResponse
    {
        $articlePrix->delete();

        return response()->json(null, 204);
    }
}
