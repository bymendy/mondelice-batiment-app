<?php

namespace App\Http\Controllers;

use App\Models\Fournisseur;
use App\Models\Fourniture;
use App\Services\Bibliotheque\SynchronisationFournitures;
use App\Services\Bibliotheque\TarifFourniture;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Base de fournitures de l'entreprise (lot F1). Aucune nouvelle permission :
 *  - lire : bibliotheque.consulter ;
 *  - créer, modifier : bibliotheque.publier_entreprise (Direction, Conducteur
 *    de travaux) : une fourniture est commune à toute l'entreprise ;
 *  - archiver : bibliotheque.supprimer (Direction).
 *
 * Prix tarif, remise et prix d'achat ne sortent qu'avec
 * bibliotheque.voir_prix_achat. Une modification met à jour les articles qui
 * utilisent la fourniture (`articles_mis_a_jour`) ; les devis ne bougent pas.
 */
class FournitureController extends Controller implements HasMiddleware
{
    private const MAX_PRIX = '99999999.9999';

    /**
     * @param TarifFourniture $tarif
     */
    public function __construct(private readonly TarifFourniture $tarif)
    {
    }

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:bibliotheque.consulter', only: ['index', 'show']),
            new Middleware('can:bibliotheque.publier_entreprise', only: ['store', 'update']),
            new Middleware('can:bibliotheque.supprimer', only: ['destroy']),
        ];
    }

    /**
     * Filtres : `recherche` (désignation, référence), `fournisseur_id`, `actif`.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Fourniture::query()->with('fournisseur');

        if ($request->filled('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }
        if ($request->filled('fournisseur_id')) {
            $query->where('fournisseur_id', $request->integer('fournisseur_id'));
        }
        if ($request->filled('recherche')) {
            $terme = (string) $request->string('recherche');
            $query->where(function ($q) use ($terme): void {
                $q->where('designation', 'ilike', "%{$terme}%")->orWhere('reference', 'ilike', "%{$terme}%");
            });
        }

        $page = $query->orderBy('designation')->orderBy('id')->paginate($request->integer('par_page', 25));
        $page->through(fn (Fourniture $fourniture): array => $this->presenter($request, $fourniture));

        return response()->json($page);
    }

    /**
     * @param Request $request
     * @param int $fourniture
     * @return JsonResponse
     */
    public function show(Request $request, int $fourniture): JsonResponse
    {
        $fiche = Fourniture::query()->with('fournisseur')->findOrFail($fourniture);

        return response()->json(
            $this->presenter($request, $fiche) + ['articles_utilisant' => SynchronisationFournitures::articlesUtilisant($fiche)]
        );
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate($this->regles(false));
        $fournisseur = $this->fournisseur($donnees['fournisseur_id'] ?? null);
        $this->exigerReferenceLibre($donnees['reference'] ?? null, $fournisseur?->getKey(), null);

        $prix = $this->tarif->calculer(
            isset($donnees['prix_tarif_ht']) ? (float) $donnees['prix_tarif_ht'] : null,
            $donnees['remise_pct'] ?? null,
            $donnees['prix_achat_ht'] ?? null,
            $donnees['prix_vente_ht'] ?? null,
            $fournisseur
        );

        $fiche = DB::transaction(function () use ($donnees, $prix, $request): Fourniture {
            $fiche = Fourniture::create($prix + $donnees);
            activity()->performedOn($fiche)->causedBy($request->user())->event('fourniture_creee')
                ->withProperties(['apres' => $prix + $donnees])
                ->log(sprintf('Fourniture « %s » créée.', $fiche->designation));

            return $fiche;
        });

        return response()->json($this->presenter($request, $fiche->refresh()->load('fournisseur')), 201);
    }

    /**
     * Un champ absent garde sa valeur. `prix_vente_ht` à null demande de le
     * recalculer depuis le prix d'achat et la marge par défaut.
     *
     * @param Request $request
     * @param int $fourniture
     * @return JsonResponse
     */
    public function update(Request $request, int $fourniture): JsonResponse
    {
        $fiche = Fourniture::query()->findOrFail($fourniture);
        $donnees = $request->validate($this->regles(true));

        $fournisseurId = array_key_exists('fournisseur_id', $donnees) ? $donnees['fournisseur_id'] : $fiche->fournisseur_id;
        $fournisseur = $this->fournisseur($fournisseurId);
        $reference = array_key_exists('reference', $donnees) ? $donnees['reference'] : $fiche->reference;
        $this->exigerReferenceLibre($reference, $fournisseur?->getKey(), (int) $fiche->getKey());

        $tarif = array_key_exists('prix_tarif_ht', $donnees) ? $donnees['prix_tarif_ht'] : $fiche->prix_tarif_ht;
        $tarif = $tarif !== null ? (float) $tarif : null;
        $remise = array_key_exists('remise_pct', $donnees) ? $donnees['remise_pct'] : $fiche->remise_pct;
        // Le prix d'achat enregistré ne compte que sans prix tarif (sinon il est calculé).
        $achat = array_key_exists('prix_achat_ht', $donnees)
            ? $donnees['prix_achat_ht']
            : ($tarif === null ? $fiche->prix_achat_ht : null);
        $vente = array_key_exists('prix_vente_ht', $donnees)
            ? $donnees['prix_vente_ht']
            : ($fiche->prix_vente_saisi ? $fiche->prix_vente_ht : null);

        $prix = $this->tarif->calculer($tarif, $remise, $achat, $vente, $fournisseur);

        $articles = DB::transaction(function () use ($fiche, $donnees, $prix, $request): int {
            $fiche->fill($prix + $donnees);
            $avant = array_intersect_key($fiche->getOriginal(), $fiche->getDirty());
            $apres = $fiche->getDirty();
            if ($apres === []) {
                return 0;
            }
            $fiche->save();
            $articles = SynchronisationFournitures::propager($fiche);

            activity()->performedOn($fiche)->causedBy($request->user())->event('fourniture_modifiee')
                ->withProperties(['avant' => $avant, 'apres' => $apres, 'articles_mis_a_jour' => $articles])
                ->log(sprintf('Fourniture « %s » modifiée (%d article(s) mis à jour).', $fiche->designation, $articles));

            return $articles;
        });

        return response()->json(
            $this->presenter($request, $fiche->refresh()->load('fournisseur')) + [
                'articles_mis_a_jour' => $articles,
                'articles_sous_seuil' => \App\Services\Bibliotheque\ArticlesSousSeuil::utilisant((int) $fiche->getKey()),
            ]
        );
    }

    /**
     * Archive la fourniture. Les articles qui l'utilisent gardent leur ligne
     * (avec le dernier prix connu) ; elle ne peut plus être ajoutée.
     *
     * @param Request $request
     * @param int $fourniture
     * @return JsonResponse
     */
    public function destroy(Request $request, int $fourniture): JsonResponse
    {
        $fiche = Fourniture::query()->findOrFail($fourniture);

        DB::transaction(function () use ($fiche, $request): void {
            $articles = SynchronisationFournitures::articlesUtilisant($fiche);
            $fiche->delete();
            activity()->performedOn($fiche)->causedBy($request->user())->event('fourniture_archivee')
                ->withProperties(['articles_utilisant' => $articles])
                ->log(sprintf('Fourniture « %s » archivée.', $fiche->designation));
        });

        return response()->json(null, 204);
    }

    /**
     * @param bool $modification
     * @return array<string, mixed>
     */
    private function regles(bool $modification): array
    {
        $requis = $modification ? ['sometimes', 'required'] : ['required'];
        $prix = ['nullable', 'numeric', 'min:0', 'max:' . self::MAX_PRIX];

        return [
            'fournisseur_id' => ['nullable', 'integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (! Fournisseur::query()->whereKey((int) $valeur)->exists()) {
                    $echec('Ce fournisseur n\'existe pas dans l\'entreprise.');
                }
            }],
            'reference' => ['nullable', 'string', 'max:80'],
            'designation' => [...$requis, 'string', 'max:255'],
            'unite' => [...$requis, 'string', 'max:20'],
            'prix_tarif_ht' => $prix,
            'remise_pct' => ['nullable', 'numeric', 'between:0,100'],
            'prix_achat_ht' => $prix,
            'prix_vente_ht' => $prix,
            'actif' => ['sometimes', 'boolean'],
            'date_tarif' => ['nullable', 'date'],
        ];
    }

    /**
     * @param mixed $id
     * @return Fournisseur|null
     */
    private function fournisseur(mixed $id): ?Fournisseur
    {
        return $id !== null ? Fournisseur::query()->find((int) $id) : null;
    }

    /**
     * Une référence n'existe qu'une fois par fournisseur.
     *
     * @param mixed $reference
     * @param int|null $fournisseurId
     * @param int|null $sauf Fourniture en cours de modification.
     * @return void
     *
     * @throws ValidationException
     */
    private function exigerReferenceLibre(mixed $reference, ?int $fournisseurId, ?int $sauf): void
    {
        if ($reference === null || $reference === '') {
            return;
        }

        $existe = Fourniture::query()
            ->where('reference', (string) $reference)
            ->when($fournisseurId === null, fn ($q) => $q->whereNull('fournisseur_id'), fn ($q) => $q->where('fournisseur_id', $fournisseurId))
            ->when($sauf !== null, fn ($q) => $q->whereKeyNot($sauf))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'reference' => 'Cette référence existe déjà pour ce fournisseur.',
            ]);
        }
    }

    /**
     * @param Request $request
     * @param Fourniture $fourniture
     * @return array<string, mixed>
     */
    private function presenter(Request $request, Fourniture $fourniture): array
    {
        $fournisseur = $fourniture->fournisseur;

        $donnees = [
            'id' => $fourniture->id,
            'fournisseur_id' => $fourniture->fournisseur_id,
            'fournisseur' => $fournisseur !== null ? ['id' => $fournisseur->id, 'nom' => $fournisseur->nom] : null,
            'reference' => $fourniture->reference,
            'designation' => $fourniture->designation,
            'unite' => $fourniture->unite,
            'prix_vente_ht' => (float) $fourniture->prix_vente_ht,
            'prix_vente_saisi' => (bool) $fourniture->prix_vente_saisi,
            'actif' => (bool) $fourniture->actif,
            'date_tarif' => $fourniture->date_tarif?->format('Y-m-d'),
        ];

        if ($request->user()?->can('bibliotheque.voir_prix_achat')) {
            $donnees['prix_tarif_ht'] = $fourniture->prix_tarif_ht !== null ? (float) $fourniture->prix_tarif_ht : null;
            $donnees['remise_pct'] = $fourniture->remise_pct !== null ? (float) $fourniture->remise_pct : null;
            $donnees['remise_appliquee_pct'] = $fourniture->prix_tarif_ht !== null
                ? TarifFourniture::remiseAppliquee($fourniture->remise_pct, $fournisseur)
                : null;
            $donnees['prix_achat_ht'] = $fourniture->prix_achat_ht !== null ? (float) $fourniture->prix_achat_ht : null;
        }

        return $donnees;
    }
}
