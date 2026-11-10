<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReordonnerComposantsRequest;
use App\Http\Requests\StoreComposantRequest;
use App\Http\Requests\UpdateComposantRequest;
use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\TauxHoraire;
use App\Models\User;
use App\Services\Bibliotheque\PresentateurArticlePrix;
use App\Services\Bibliotheque\PresentateurComposant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sous-detail d'un article de la Bibliotheque de prix (lot 5) : lignes de
 * main-d'oeuvre, fournitures, materiel et frais, « pour 1 unite » de l'article.
 *
 * Lecture : `bibliotheque.consulter`, sur tout article visible (reference
 * comprise). Ecriture : `bibliotheque.personnaliser` (soumis au reglage du
 * Commercial) et le droit de modifier l'article lui-meme (voir
 * ArticlePrix::verifierModifiablePar()) : une reference ne se modifie pas, un
 * article d'entreprise demande `bibliotheque.publier_entreprise`.
 *
 * Les prix d'achat et revients ne sont renvoyes qu'avec
 * `bibliotheque.voir_prix_achat`. Chaque ecriture renvoie aussi l'article
 * recalcule (le prix n'est jamais stocke).
 */
class ArticleComposantController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:bibliotheque.consulter', only: ['index']),
            new Middleware('can:bibliotheque.personnaliser', only: ['store', 'update', 'destroy', 'reordonner']),
            new Middleware(\App\Http\Middleware\BibliothequePrixModifiable::class, only: ['store', 'update', 'destroy', 'reordonner']),
        ];
    }

    /**
     * Lignes du sous-detail, dans l'ordre, avec l'article recalcule.
     *
     * @param Request $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function index(Request $request, ArticlePrix $articlePrix): JsonResponse
    {
        return response()->json($this->sousDetail($request->user(), $articlePrix));
    }

    /**
     * Ajoute une ligne. Sans `ordre`, la ligne passe en dernier. Une ligne de
     * main-d'oeuvre prend l'unite `h` et, sans designation, le libelle du taux.
     *
     * @param StoreComposantRequest $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function store(StoreComposantRequest $request, ArticlePrix $articlePrix): JsonResponse
    {
        $donnees = $request->validated();

        if ($donnees['type'] === 'main_oeuvre') {
            $taux = TauxHoraire::indexesParCode()[$donnees['qualification']];
            $donnees['unite'] = 'h';
            $donnees['designation'] = ! empty($donnees['designation']) ? $donnees['designation'] : $taux->libelle;
        }

        $composant = DB::transaction(function () use ($articlePrix, $donnees): ArticleComposant {
            // Pas de max() sur la relation : elle est triee, ce que PostgreSQL refuse avec un agregat.
            $donnees['ordre'] ??= ((int) ArticleComposant::query()->where('article_id', $articlePrix->id)->max('ordre')) + 1;

            return $articlePrix->composants()->create($donnees);
        });

        return response()->json($this->ligneEtArticle($request->user(), $articlePrix, $composant), 201);
    }

    /**
     * Modifie une ligne.
     *
     * @param UpdateComposantRequest $request
     * @param ArticlePrix $articlePrix
     * @param ArticleComposant $composant
     * @return JsonResponse
     */
    public function update(UpdateComposantRequest $request, ArticlePrix $articlePrix, ArticleComposant $composant): JsonResponse
    {
        $composant->update($request->validated());

        return response()->json($this->ligneEtArticle($request->user(), $articlePrix, $composant->refresh()));
    }

    /**
     * Supprime une ligne (le sous-detail n'est pas archive : il n'est pas
     * reference par les devis, qui gardent leur propre copie).
     *
     * @param Request $request
     * @param ArticlePrix $articlePrix
     * @param ArticleComposant $composant
     * @return JsonResponse
     */
    public function destroy(Request $request, ArticlePrix $articlePrix, ArticleComposant $composant): JsonResponse
    {
        abort_unless($composant->article_id === $articlePrix->id, 404);
        $articlePrix->verifierModifiablePar($request->user());

        $composant->delete();

        return response()->json($this->sousDetail($request->user(), $articlePrix));
    }

    /**
     * Reordonne le sous-detail : `ids` doit contenir toutes les lignes de
     * l'article, une fois chacune, dans le nouvel ordre.
     *
     * @param ReordonnerComposantsRequest $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     *
     * @throws ValidationException Si la liste ne correspond pas aux lignes de l'article.
     */
    public function reordonner(ReordonnerComposantsRequest $request, ArticlePrix $articlePrix): JsonResponse
    {
        $ids = array_map('intval', $request->validated()['ids']);
        $existants = ArticleComposant::query()->where('article_id', $articlePrix->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $attendus = $existants;
        sort($attendus);
        $recus = $ids;
        sort($recus);

        if ($attendus !== $recus) {
            throw ValidationException::withMessages([
                'ids' => 'La liste doit contenir toutes les lignes du sous-détail, une fois chacune.',
            ]);
        }

        DB::transaction(function () use ($ids, $articlePrix): void {
            foreach ($ids as $position => $id) {
                ArticleComposant::query()->where('article_id', $articlePrix->id)->whereKey($id)->update(['ordre' => $position + 1]);
            }
        });

        return response()->json($this->sousDetail($request->user(), $articlePrix));
    }

    /**
     * @param User|null $utilisateur
     * @param ArticlePrix $article
     * @return array<string, mixed>
     */
    private function sousDetail(?User $utilisateur, ArticlePrix $article): array
    {
        $article->load('composants');
        $presentateur = PresentateurComposant::pour($utilisateur);

        return [
            'data' => $article->composants->map(fn (ArticleComposant $c): array => $presentateur->presenter($c))->all(),
            'article' => PresentateurArticlePrix::pour($utilisateur)->presenter($article),
        ];
    }

    /**
     * @param User|null $utilisateur
     * @param ArticlePrix $article
     * @param ArticleComposant $composant
     * @return array<string, mixed>
     */
    private function ligneEtArticle(?User $utilisateur, ArticlePrix $article, ArticleComposant $composant): array
    {
        $article->load('composants');

        return [
            'composant' => PresentateurComposant::pour($utilisateur)->presenter($composant),
            'article' => PresentateurArticlePrix::pour($utilisateur)->presenter($article),
        ];
    }
}
