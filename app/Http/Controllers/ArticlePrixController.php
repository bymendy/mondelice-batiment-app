<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreArticlePrixRequest;
use App\Http\Requests\UpdateArticlePrixRequest;
use App\Models\Activity;
use App\Models\ArticlePrix;
use App\Models\User;
use App\Services\Bibliotheque\ControleSeuilsPrixForce;
use App\Services\Bibliotheque\JournalPrix;
use App\Services\Bibliotheque\PresentateurHistorique;
use App\Services\Bibliotheque\PresentateurArticlePrix;
use App\Services\Bibliotheque\VersionsArticlePrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRUD de la Bibliotheque de prix (taches 3.3 et 3.4, lots 3 et 4).
 *
 * Catalogue de l'entreprise, plus les articles de reference partages (en
 * lecture seule). L'autorisation se fait par permission Spatie
 * "bibliotheque.*" via le middleware natif "can:".
 *
 * Lot 4 : un article de l'entreprise est `prive` (visible de son proprietaire
 * seul) ou `entreprise` (visible de tous). Qui a `bibliotheque.publier_entreprise`
 * (Direction, Conducteur travaux) cree et modifie les articles d'entreprise ;
 * les autres ne travaillent que sur leurs versions privees. Dans la liste, un
 * article n'apparait qu'une fois, dans sa version affichee : privee, sinon
 * d'entreprise, sinon de reference.
 */
class ArticlePrixController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly VersionsArticlePrix $versions,
        private readonly JournalPrix $journal,
    )
    {
    }

    /**
     * Permission requise par action.
     *
     * Archiver (destroy) est controle dans l'action : la permission
     * `bibliotheque.supprimer` OU etre proprietaire de la version privee.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:bibliotheque.consulter', only: ['index', 'show', 'destroy', 'historique']),
            new Middleware('can:bibliotheque.personnaliser', only: ['store', 'update', 'personnaliser']),
            new Middleware('can:bibliotheque.publier_entreprise', only: ['publier']),
            new Middleware(\App\Http\Middleware\BibliothequePrixModifiable::class, only: ['store', 'update', 'personnaliser']),
        ];
    }

    /**
     * Liste paginee des articles visibles, une seule version par article.
     *
     * Query params :
     *  - actif=0|1          filtre sur le statut actif (defaut : tous)
     *  - type_article=...   filtre sur le type (materiaux, main_oeuvre...)
     *  - categorie_id=...   filtre sur la categorie de l'arborescence
     *  - portee=...         `prive` ou `entreprise` (les references n'ont pas de portee)
     *  - recherche=...      recherche texte sur reference/designation/fournisseur
     *  - orphelines=1       Direction seule : versions privees dont le proprietaire
     *                       a ete supprime (liste a part, sans resolution de version)
     *  - par_page=...       taille de page (defaut 25)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['portee' => ['nullable', Rule::in(['prive', 'entreprise'])]]);

        /** @var User $utilisateur */
        $utilisateur = $request->user();
        $query = ArticlePrix::query()->with('composants');

        if ($request->boolean('orphelines')) {
            abort_unless($utilisateur->hasRole('Direction'), 403, 'Les versions orphelines sont réservées à la Direction.');
            $query->orphelines();
        } else {
            $query->visiblesPour($utilisateur)->versionAffichee($utilisateur);
        }

        if ($request->filled('portee')) {
            $query->where('articles_prix.portee', $request->string('portee'));
        }

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
        $presentateur = PresentateurArticlePrix::pour($utilisateur);
        $page->through(fn (ArticlePrix $article): array => $presentateur->presenter($article));

        return response()->json($page);
    }

    /**
     * Cree un nouvel article.
     *
     * `portee` est facultative : `entreprise` pour qui peut publier, `prive`
     * pour les autres. Demander `entreprise` sans en avoir le droit donne 403.
     * `reference` est facultative : un code automatique est attribue.
     *
     * @param StoreArticlePrixRequest $request
     * @return JsonResponse
     */
    public function store(StoreArticlePrixRequest $request): JsonResponse
    {
        /** @var User $utilisateur */
        $utilisateur = $request->user();
        $donnees = $request->validated();
        $portee = $this->porteeDemandee(Arr::pull($donnees, 'portee'), $utilisateur);

        // A la creation il n'y a pas encore de sous-detail : avec des seuils actifs, un prix force
        // n'est accepte que pour la Direction (les autres le posent apres avoir decrit le cout).
        $this->controlerPrixForce($utilisateur, $donnees['prix_force_ht'] ?? null, [], null);

        $article = DB::transaction(function () use ($utilisateur, $donnees, $portee): ArticlePrix {
            $article = $this->versions->creer($utilisateur, $donnees, $portee);
            $this->journal->articleCree($utilisateur, $article);

            return $article;
        })->load('composants');

        return response()->json(PresentateurArticlePrix::pour($utilisateur)->presenter($article), 201);
    }

    /**
     * Affiche un article (de l'entreprise, a soi, ou de reference).
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
     * Met a jour un article. Un article de reference ne se modifie pas (403) ;
     * un article d'entreprise se modifie avec `bibliotheque.publier_entreprise`.
     * La portee ne change jamais ici (voir publier()).
     *
     * @param UpdateArticlePrixRequest $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function update(UpdateArticlePrixRequest $request, ArticlePrix $articlePrix): JsonResponse
    {
        /** @var User $utilisateur */
        $utilisateur = $request->user();

        $articlePrix->verifierModifiablePar($utilisateur);

        $donnees = $request->validated();
        $this->controlerPrixForce($utilisateur, $donnees['prix_force_ht'] ?? null, $articlePrix->composants, $articlePrix->prix_force_ht);

        DB::transaction(function () use ($articlePrix, $donnees, $utilisateur): void {
            $avant = $this->journal->instantane($articlePrix, JournalPrix::CHAMPS_ARTICLE);
            $articlePrix->update($donnees);
            $this->journal->articleModifie($utilisateur, $articlePrix, $avant);
        });
        $articlePrix->load('composants');

        return response()->json(PresentateurArticlePrix::pour($utilisateur)->presenter($articlePrix));
    }

    /**
     * Archive l'article (soft delete) -- jamais de suppression definitive
     * tant qu'un devis peut encore le referencer. Un article de reference ne
     * s'archive pas (403). Archivage permis avec `bibliotheque.supprimer`, ou
     * a son proprietaire pour sa version privee.
     *
     * @param Request $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function destroy(Request $request, ArticlePrix $articlePrix): JsonResponse
    {
        /** @var User $utilisateur */
        $utilisateur = $request->user();

        abort_if($articlePrix->estReference(), 403, 'Un article de référence ne s\'archive pas.');

        $estProprietaire = $articlePrix->portee === 'prive'
            && (int) $articlePrix->proprietaire_id === (int) $utilisateur->getKey();
        abort_unless($utilisateur->can('bibliotheque.supprimer') || $estProprietaire, 403, 'Vous ne pouvez pas archiver cet article.');

        $articlePrix->delete();

        return response()->json(null, 204);
    }

    /**
     * Cree la version a soi d'une reference (ou d'une version de l'entreprise),
     * avec son propre code. Reponse 201 avec la nouvelle version.
     *
     * @param Request $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function personnaliser(Request $request, ArticlePrix $articlePrix): JsonResponse
    {
        $request->validate(['portee' => ['nullable', Rule::in(['prive', 'entreprise'])]]);

        /** @var User $utilisateur */
        $utilisateur = $request->user();
        $portee = $this->porteeDemandee($request->input('portee'), $utilisateur);

        $copie = DB::transaction(function () use ($articlePrix, $utilisateur, $portee): ArticlePrix {
            $copie = $this->versions->personnaliser($articlePrix, $utilisateur, $portee);
            $this->journal->articlePersonnalise($utilisateur, $copie, $articlePrix);

            return $copie;
        });

        return response()->json(PresentateurArticlePrix::pour($utilisateur)->presenter($copie), 201);
    }

    /**
     * Publie une version privee pour toute l'entreprise (Direction, Conducteur
     * travaux). La Direction peut aussi publier une version orpheline.
     *
     * @param Request $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function publier(Request $request, ArticlePrix $articlePrix): JsonResponse
    {
        /** @var User $utilisateur */
        $utilisateur = $request->user();

        $article = DB::transaction(function () use ($articlePrix, $utilisateur): ArticlePrix {
            $avant = $this->journal->instantane($articlePrix, ['portee', 'proprietaire_id']);
            $article = $this->versions->publier($articlePrix);
            $this->journal->articlePublie($utilisateur, $article, $avant);

            return $article;
        })->load('composants');

        return response()->json(PresentateurArticlePrix::pour($request->user())->presenter($article));
    }

    /**
     * Historique des changements de prix d'un article : prix force, portee,
     * sous-detail. Du plus recent au plus ancien, avec l'auteur et les valeurs
     * avant et apres. Visible par qui voit l'article ; les prix d'achat ne sont
     * lisibles qu'avec `bibliotheque.voir_prix_achat`.
     *
     * Query params : par_page (defaut 25).
     *
     * @param Request $request
     * @param ArticlePrix $articlePrix
     * @return JsonResponse
     */
    public function historique(Request $request, ArticlePrix $articlePrix): JsonResponse
    {
        $page = Activity::query()
            ->where('subject_type', $articlePrix->getMorphClass())
            ->where('subject_id', $articlePrix->getKey())
            ->with('causer')
            ->orderByDesc('id')
            ->paginate($request->integer('par_page', 25));

        $presentateur = PresentateurHistorique::pour($request->user());
        $page->through(fn (Activity $entree): array => $presentateur->presenter($entree));

        return response()->json($page);
    }

    /**
     * Controle un prix force pose a la main contre les seuils de la Direction.
     *
     * Ne controle pas : la Direction (permission `parametres.seuils`), la
     * suppression du prix force (null), ni un prix inchange (sinon un article
     * deja sous un seuil nouvellement fixe ne pourrait plus changer de
     * designation).
     *
     * @param User $utilisateur
     * @param mixed $demande Prix force demande, ou null.
     * @param iterable<\App\Models\ArticleComposant> $composants Sous-detail actuel.
     * @param mixed $actuel Prix force actuel de l'article, ou null.
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException 422 si le prix est sous le seuil ou non verifiable.
     */
    private function controlerPrixForce(User $utilisateur, mixed $demande, iterable $composants, mixed $actuel): void
    {
        if ($demande === null || $utilisateur->can('parametres.seuils')) {
            return;
        }

        if ($actuel !== null && abs((float) $actuel - (float) $demande) < 0.005) {
            return;
        }

        ControleSeuilsPrixForce::pourEntrepriseCourante()->verifier($composants, (float) $demande);
    }

    /**
     * Portee effective d'une creation : celle demandee, sinon `entreprise` pour
     * qui peut publier et `prive` pour les autres.
     *
     * @param mixed $demandee
     * @param User $utilisateur
     * @return string `prive` ou `entreprise`.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403 si `entreprise` est demandee sans le droit de publier.
     */
    private function porteeDemandee(mixed $demandee, User $utilisateur): string
    {
        $peutPublier = $utilisateur->can('bibliotheque.publier_entreprise');
        $portee = is_string($demandee) && $demandee !== '' ? $demandee : ($peutPublier ? 'entreprise' : 'prive');

        abort_if(
            $portee === 'entreprise' && ! $peutPublier,
            403,
            'Seule la Direction ou le Conducteur travaux crée un article pour toute l\'entreprise.'
        );

        return $portee;
    }
}
