<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDevisRequest;
use App\Http\Requests\UpdateDevisRequest;
use App\Models\Devis;
use App\Services\Devis\AccesDevis;
use App\Services\Devis\CalculTotauxDevis;
use App\Services\Devis\NumerotationDevis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Devis : brouillon, lots, lignes et totaux. Les statuts, la copie figee
 * depuis la bibliotheque de prix, les remises et les avenants viennent dans
 * les lots suivants.
 */
class DevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:devis.voir', only: ['index', 'show']),
            new Middleware('can:devis.creer', only: ['store']),
            new Middleware('can:devis.modifier', only: ['update']),
            new Middleware('can:devis.supprimer', only: ['destroy']),
        ];
    }

    /**
     * Liste paginee.
     *
     * Query params : client_id, opportunite_id, statut, auteur_id,
     * recherche (numero, titre, nom du client), par_page (defaut 25, max 100).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $requete = AccesDevis::visibles($request->user())->with(['client', 'auteur:id,name']);

        foreach (['client_id', 'opportunite_id', 'auteur_id'] as $champ) {
            if ($request->filled($champ)) {
                $requete->where($champ, $request->integer($champ));
            }
        }

        if ($request->filled('statut')) {
            $requete->where('statut', (string) $request->input('statut'));
        }

        if ($request->filled('recherche')) {
            $terme = $request->string('recherche');
            $requete->where(function (Builder $q) use ($terme): void {
                $q->where('numero', 'ilike', "%{$terme}%")
                    ->orWhere('titre', 'ilike', "%{$terme}%")
                    ->orWhereHas('client', fn (Builder $c) => $c
                        ->where('nom', 'ilike', "%{$terme}%")
                        ->orWhere('prenom', 'ilike', "%{$terme}%")
                        ->orWhere('raison_sociale', 'ilike', "%{$terme}%"));
            });
        }

        $parPage = min(max($request->integer('par_page', 25), 1), 100);

        return response()->json($requete->orderByDesc('id')->paginate($parPage));
    }

    /**
     * Cree un brouillon numerote. L'auteur est l'utilisateur connecte ; le
     * devis est prive par defaut ; la date du jour et 30 jours de validite
     * sont poses si rien n'est donne.
     *
     * @param StoreDevisRequest $request
     * @param NumerotationDevis $numerotation
     * @return JsonResponse
     */
    public function store(StoreDevisRequest $request, NumerotationDevis $numerotation): JsonResponse
    {
        $donnees = array_merge([
            'date_devis' => now()->toDateString(),
            'validite_jours' => 30,
        ], $request->validated());
        $donnees['auteur_id'] = $request->user()->getKey();

        $devis = DB::transaction(function () use ($donnees, $numerotation): Devis {
            $devis = new Devis($donnees);
            $devis->date_expiration = $devis->date_devis->copy()->addDays($devis->validite_jours);
            $devis->save();
            $numerotation->attribuer($devis);

            return $devis;
        });

        activity()
            ->performedOn($devis)
            ->causedBy($request->user())
            ->event('created')
            ->log(sprintf('Devis %s créé.', $devis->numero));

        return response()->json($this->fiche($devis->refresh()), 201);
    }

    /**
     * Fiche d'un devis : lots, lignes et detail des totaux (par taux de TVA
     * et par lot).
     *
     * @param Request $request
     * @param int $devis
     * @return JsonResponse
     */
    public function show(Request $request, int $devis): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);

        return response()->json($this->fiche($fiche));
    }

    /**
     * Modifie l'en-tete d'un brouillon (le client ne change pas). La date
     * d'expiration suit la date du devis et la validite.
     *
     * @param UpdateDevisRequest $request
     * @param int $devis
     * @return JsonResponse
     */
    public function update(UpdateDevisRequest $request, int $devis): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);
        AccesDevis::exigerModifiable($fiche);

        $fiche->fill($request->validated());
        $fiche->date_expiration = $fiche->date_devis?->copy()->addDays($fiche->validite_jours);
        $fiche->save();

        return response()->json($this->fiche($fiche->refresh()));
    }

    /**
     * Archive un brouillon (suppression douce). Son numero n'est pas reutilise.
     *
     * @param Request $request
     * @param int $devis
     * @return JsonResponse
     */
    public function destroy(Request $request, int $devis): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);
        AccesDevis::exigerModifiable($fiche);

        $fiche->delete();

        activity()
            ->performedOn($fiche)
            ->causedBy($request->user())
            ->event('deleted')
            ->log(sprintf('Devis %s supprimé.', $fiche->numero));

        return response()->json(null, 204);
    }

    /**
     * Devis avec ses liens, ses lots, ses lignes et le detail des totaux.
     *
     * @param Devis $devis
     * @return array<string, mixed>
     */
    private function fiche(Devis $devis): array
    {
        $devis->load(['client', 'opportunite:id,titre', 'adresseChantier', 'auteur:id,name', 'lots', 'lignes']);

        return array_merge($devis->toArray(), [
            'detail_totaux' => (new CalculTotauxDevis())->calculer($devis->lignes),
        ]);
    }
}
