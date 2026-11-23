<?php

namespace App\Http\Controllers;

use App\Models\Fournisseur;
use App\Models\Fourniture;
use App\Services\Bibliotheque\SynchronisationFournitures;
use App\Services\Bibliotheque\TarifFourniture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fournisseurs de l'entreprise (lot F1). Aucune nouvelle permission :
 *  - lire : bibliotheque.consulter ;
 *  - créer, modifier : bibliotheque.publier_entreprise (Direction, Conducteur
 *    de travaux) : un fournisseur est commun à toute l'entreprise ;
 *  - archiver : bibliotheque.supprimer (Direction).
 *
 * La remise par défaut est une condition d'achat : elle ne sort qu'avec
 * bibliotheque.voir_prix_achat. La changer recalcule les fournitures qui
 * n'ont pas leur propre remise, puis les articles qui les utilisent.
 */
class FournisseurController extends Controller implements HasMiddleware
{
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
     * Filtres : `recherche` (nom, référence), `actif`.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Fournisseur::query();

        if ($request->filled('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }
        if ($request->filled('recherche')) {
            $terme = (string) $request->string('recherche');
            $query->where(function ($q) use ($terme): void {
                $q->where('nom', 'ilike', "%{$terme}%")->orWhere('reference', 'ilike', "%{$terme}%");
            });
        }

        $page = $query->orderBy('nom')->paginate($request->integer('par_page', 25));
        $page->through(fn (Fournisseur $fournisseur): array => $this->presenter($request, $fournisseur));

        return response()->json($page);
    }

    /**
     * @param Request $request
     * @param int $fournisseur
     * @return JsonResponse
     */
    public function show(Request $request, int $fournisseur): JsonResponse
    {
        return response()->json($this->presenter($request, Fournisseur::query()->findOrFail($fournisseur)));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate($this->regles(false));

        $fiche = DB::transaction(function () use ($donnees, $request): Fournisseur {
            $fiche = Fournisseur::create($donnees);
            activity()->performedOn($fiche)->causedBy($request->user())->event('fournisseur_cree')
                ->withProperties(['apres' => $donnees])
                ->log(sprintf('Fournisseur « %s » créé.', $fiche->nom));

            return $fiche;
        });

        return response()->json($this->presenter($request, $fiche->refresh()), 201);
    }

    /**
     * @param Request $request
     * @param int $fournisseur
     * @param TarifFourniture $tarif
     * @return JsonResponse
     */
    public function update(Request $request, int $fournisseur, TarifFourniture $tarif): JsonResponse
    {
        $fiche = Fournisseur::query()->findOrFail($fournisseur);
        $donnees = $request->validate($this->regles(true));

        $bilan = DB::transaction(function () use ($fiche, $donnees, $request, $tarif): array {
            $ancienneRemise = (float) $fiche->remise_defaut_pct;
            $fiche->fill($donnees);
            $avant = array_intersect_key($fiche->getOriginal(), $fiche->getDirty());
            $apres = $fiche->getDirty();
            $fiche->save();

            $bilan = ['fournitures_recalculees' => 0, 'articles_mis_a_jour' => 0];

            // La remise par défaut change : les fournitures sans remise propre suivent.
            if (abs((float) $fiche->remise_defaut_pct - $ancienneRemise) >= 0.005) {
                $fournitures = Fourniture::query()
                    ->where('fournisseur_id', $fiche->getKey())
                    ->whereNull('remise_pct')
                    ->whereNotNull('prix_tarif_ht')
                    ->get();
                foreach ($fournitures as $fourniture) {
                    $fourniture->fill($tarif->recalculer($fourniture, $fiche));
                    if ($fourniture->isDirty()) {
                        $fourniture->save();
                        $bilan['fournitures_recalculees']++;
                        $bilan['articles_mis_a_jour'] += SynchronisationFournitures::propager($fourniture);
                    }
                }
            }

            if ($apres !== []) {
                activity()->performedOn($fiche)->causedBy($request->user())->event('fournisseur_modifie')
                    ->withProperties(['avant' => $avant, 'apres' => $apres] + $bilan)
                    ->log(sprintf('Fournisseur « %s » modifié.', $fiche->nom));
            }

            return $bilan;
        });

        return response()->json($this->presenter($request, $fiche->refresh()) + $bilan);
    }

    /**
     * Archive le fournisseur. Refusé tant qu'il a des fournitures non archivées.
     *
     * @param Request $request
     * @param int $fournisseur
     * @return JsonResponse
     */
    public function destroy(Request $request, int $fournisseur): JsonResponse
    {
        $fiche = Fournisseur::query()->findOrFail($fournisseur);

        if (Fourniture::query()->where('fournisseur_id', $fiche->getKey())->exists()) {
            throw ValidationException::withMessages([
                'fournisseur' => 'Ce fournisseur a encore des fournitures : archivez-les ou changez leur fournisseur d\'abord.',
            ]);
        }

        DB::transaction(function () use ($fiche, $request): void {
            $fiche->delete();
            activity()->performedOn($fiche)->causedBy($request->user())->event('fournisseur_archive')
                ->log(sprintf('Fournisseur « %s » archivé.', $fiche->nom));
        });

        return response()->json(null, 204);
    }

    /**
     * @param bool $modification
     * @return array<string, mixed>
     */
    private function regles(bool $modification): array
    {
        return [
            'nom' => [$modification ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:50'],
            'contact_nom' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'adresse' => ['nullable', 'string', 'max:2000'],
            'siret' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'remise_defaut_pct' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param Request $request
     * @param Fournisseur $fournisseur
     * @return array<string, mixed>
     */
    private function presenter(Request $request, Fournisseur $fournisseur): array
    {
        $donnees = [
            'id' => $fournisseur->id,
            'nom' => $fournisseur->nom,
            'reference' => $fournisseur->reference,
            'contact_nom' => $fournisseur->contact_nom,
            'email' => $fournisseur->email,
            'telephone' => $fournisseur->telephone,
            'adresse' => $fournisseur->adresse,
            'siret' => $fournisseur->siret,
            'notes' => $fournisseur->notes,
            'actif' => (bool) $fournisseur->actif,
        ];
        if ($request->user()?->can('bibliotheque.voir_prix_achat')) {
            $donnees['remise_defaut_pct'] = (float) $fournisseur->remise_defaut_pct;
        }

        return $donnees;
    }
}
