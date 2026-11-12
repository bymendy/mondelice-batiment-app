<?php

namespace App\Http\Controllers;

use App\Http\Requests\RemplacerSousDetailRequest;
use App\Http\Requests\ReordonnerComposantsRequest;
use App\Http\Requests\StoreComposantRequest;
use App\Http\Requests\UpdateComposantRequest;
use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\TauxHoraire;
use App\Models\User;
use App\Services\Bibliotheque\JournalPrix;
use App\Services\Bibliotheque\PresentateurArticlePrix;
use App\Services\Bibliotheque\PresentateurComposant;
use App\Services\Bibliotheque\PrixVenteFourniture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Arr;
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
    public function __construct(
        private readonly JournalPrix $journal,
        private readonly PrixVenteFourniture $prixVente,
    ) {
    }

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:bibliotheque.consulter', only: ['index']),
            new Middleware('can:bibliotheque.personnaliser', only: ['store', 'update', 'destroy', 'reordonner', 'remplacer']),
            new Middleware(\App\Http\Middleware\BibliothequePrixModifiable::class, only: ['store', 'update', 'destroy', 'reordonner', 'remplacer']),
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
        } else {
            // Prix de vente saisi, sinon calcule depuis le prix d'achat avec la marge par defaut.
            $donnees['prix_vente_ht'] = $this->prixVente->resoudre($donnees['prix_vente_ht'] ?? null, $donnees['prix_achat_ht'] ?? null);
        }

        $utilisateur = $request->user();

        $composant = DB::transaction(function () use ($articlePrix, $donnees, $utilisateur): ArticleComposant {
            // Pas de max() sur la relation : elle est triee, ce que PostgreSQL refuse avec un agregat.
            $donnees['ordre'] ??= ((int) ArticleComposant::query()->where('article_id', $articlePrix->id)->max('ordre')) + 1;

            $composant = $articlePrix->composants()->create($donnees);
            $this->journal->composantAjoute($utilisateur, $articlePrix, $composant);

            return $composant;
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
        $utilisateur = $request->user();

        DB::transaction(function () use ($composant, $request, $articlePrix, $utilisateur): void {
            $avant = $this->journal->instantane($composant, JournalPrix::CHAMPS_COMPOSANT);
            $composant->update($request->validated());
            $this->journal->composantModifie($utilisateur, $articlePrix, $composant, $avant);
        });

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

        $utilisateur = $request->user();

        DB::transaction(function () use ($composant, $articlePrix, $utilisateur): void {
            $avant = $this->journal->instantane($composant, JournalPrix::CHAMPS_COMPOSANT);
            $composant->delete();
            $this->journal->composantSupprime($utilisateur, $articlePrix, (int) $composant->getKey(), $avant);
        });

        return response()->json($this->sousDetail($request->user(), $articlePrix));
    }

    /**
     * Enregistre le sous-detail complet en une seule operation (tout ou rien).
     *
     * Corps : `composants`, la liste des lignes dans l'ordre voulu, chacune decrite
     * en entier. Avec `id`, la ligne existante est mise a jour (son type ne change
     * pas) ; sans `id`, elle est creee ; une ligne existante absente de la liste est
     * supprimee. Le prix de vente d'une fourniture peut etre omis si le prix d'achat
     * est donne. Une seule entree de journal (`sous_detail_modifie`).
     *
     * @param RemplacerSousDetailRequest $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse Le sous-detail et l'article recalcule.
     */
    public function remplacer(RemplacerSousDetailRequest $request, ArticlePrix $articlePrix): JsonResponse
    {
        $utilisateur = $request->user();
        $lignes = array_values((array) $request->input('composants', []));
        $taux = TauxHoraire::indexesParCode();

        DB::transaction(function () use ($lignes, $taux, $articlePrix, $utilisateur): void {
            $existants = ArticleComposant::query()
                ->where('article_id', $articlePrix->id)
                ->orderBy('ordre')
                ->orderBy('id')
                ->get()
                ->keyBy('id');
            $avant = $existants->map(fn (ArticleComposant $c): array => $this->journal->instantaneLigne($c))->values()->all();
            $gardees = [];

            foreach ($lignes as $position => $ligne) {
                $donnees = $this->donneesLigne($ligne, $position, $taux);
                $id = isset($ligne['id']) && is_numeric($ligne['id']) ? (int) $ligne['id'] : null;

                if ($id !== null) {
                    $existants->get($id)->update(Arr::except($donnees, ['type']));
                    $gardees[$id] = true;
                } else {
                    $articlePrix->composants()->create($donnees);
                }
            }

            foreach ($existants as $id => $composant) {
                if (! isset($gardees[$id])) {
                    $composant->delete();
                }
            }

            $apres = ArticleComposant::query()
                ->where('article_id', $articlePrix->id)
                ->orderBy('ordre')
                ->orderBy('id')
                ->get()
                ->map(fn (ArticleComposant $c): array => $this->journal->instantaneLigne($c))
                ->values()
                ->all();

            $this->journal->sousDetailModifie($utilisateur, $articlePrix, $avant, $apres);
        });

        return response()->json($this->sousDetail($utilisateur, $articlePrix));
    }

    /**
     * Champs a enregistrer pour une ligne d'un sous-detail complet (la ligne est deja validee).
     *
     * @param array<string, mixed> $ligne
     * @param int $position Position dans la liste (0 pour la premiere).
     * @param array<string, TauxHoraire> $taux Taux de l'entreprise, par code.
     * @return array<string, mixed>
     */
    private function donneesLigne(array $ligne, int $position, array $taux): array
    {
        $commun = ['quantite' => $ligne['quantite'], 'ordre' => $position + 1];

        if ($ligne['type'] === 'main_oeuvre') {
            $designation = $ligne['designation'] ?? null;

            return $commun + [
                'type' => 'main_oeuvre',
                'qualification' => $ligne['qualification'],
                'designation' => ($designation !== null && $designation !== '') ? $designation : $taux[$ligne['qualification']]->libelle,
                'unite' => 'h',
                'prix_achat_ht' => null,
                'prix_vente_ht' => null,
            ];
        }

        return $commun + [
            'type' => $ligne['type'],
            'qualification' => null,
            'designation' => $ligne['designation'],
            'unite' => $ligne['unite'],
            'prix_achat_ht' => $ligne['prix_achat_ht'] ?? null,
            'prix_vente_ht' => $this->prixVente->resoudre(
                $ligne['prix_vente_ht'] ?? null,
                $ligne['prix_achat_ht'] ?? null,
                "composants.{$position}.prix_vente_ht"
            ),
        ];
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

        $utilisateur = $request->user();
        $ancienOrdre = ArticleComposant::query()->where('article_id', $articlePrix->id)->orderBy('ordre')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($ids, $articlePrix, $utilisateur, $ancienOrdre): void {
            foreach ($ids as $position => $id) {
                ArticleComposant::query()->where('article_id', $articlePrix->id)->whereKey($id)->update(['ordre' => $position + 1]);
            }

            $this->journal->composantsReordonnes($utilisateur, $articlePrix, $ancienOrdre, $ids);
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
