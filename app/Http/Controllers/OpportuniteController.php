<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangerEtapeRequest;
use App\Http\Requests\StoreOpportuniteRequest;
use App\Http\Requests\UpdateOpportuniteRequest;
use App\Models\EtapePipeline;
use App\Models\Opportunite;
use App\Services\Crm\ChangementEtape;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Affaires (opportunites) du pipeline commercial.
 *
 * Visibilite : la Direction (opportunites.voir_toutes) voit toutes les
 * affaires de l'entreprise ; les autres ne voient que celles dont elles sont
 * le commercial responsable. Une affaire hors de leur perimetre est
 * introuvable (404), pas interdite : on ne confirme pas son existence.
 */
class OpportuniteController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:opportunites.voir', only: ['index', 'show']),
            new Middleware('can:opportunites.creer', only: ['store']),
            new Middleware('can:opportunites.modifier', only: ['update', 'changerEtape']),
            new Middleware('can:opportunites.supprimer', only: ['destroy']),
        ];
    }

    /**
     * Liste paginee.
     *
     * Query params :
     *  - etape_id=...  client_id=...  source_id=...
     *  - commercial_id=...  (Direction seulement ; ignore pour les autres)
     *  - statut=ouvertes|cloturees
     *  - en_retard=1   prochaine action depassee (affaires ouvertes)
     *  - recherche=... sur le titre et le nom du client
     *  - par_page=...  (defaut 25, maximum 100)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $requete = $this->perimetre($request)->with($this->resume());

        foreach (['etape_id', 'client_id', 'source_id'] as $champ) {
            if ($request->filled($champ)) {
                $requete->where($champ, $request->integer($champ));
            }
        }

        if ($request->filled('commercial_id') && $request->user()->can('opportunites.voir_toutes')) {
            $requete->where('commercial_id', $request->integer('commercial_id'));
        }

        if ($request->input('statut') === 'ouvertes') {
            $requete->whereHas('etape', fn (Builder $q) => $q->where('type', EtapePipeline::TYPE_OUVERTE));
        } elseif ($request->input('statut') === 'cloturees') {
            $requete->whereHas('etape', fn (Builder $q) => $q->where('type', '!=', EtapePipeline::TYPE_OUVERTE));
        }

        if ($request->boolean('en_retard')) {
            $requete->whereDate('prochaine_action_le', '<', now()->toDateString())
                ->whereHas('etape', fn (Builder $q) => $q->where('type', EtapePipeline::TYPE_OUVERTE));
        }

        if ($request->filled('recherche')) {
            $terme = $request->string('recherche');
            $requete->where(function (Builder $q) use ($terme): void {
                $q->where('titre', 'ilike', "%{$terme}%")
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
     * Cree une affaire : etape « Nouveau » par defaut, commercial = auteur
     * (la Direction peut en designer un autre).
     *
     * @param StoreOpportuniteRequest $request
     * @return JsonResponse
     */
    public function store(StoreOpportuniteRequest $request): JsonResponse
    {
        $donnees = $request->validated();

        if (! $request->user()->can('opportunites.voir_toutes') || empty($donnees['commercial_id'])) {
            $donnees['commercial_id'] = $request->user()->getKey();
        }

        $affaire = Opportunite::create($donnees)->refresh();

        activity()
            ->performedOn($affaire)
            ->causedBy($request->user())
            ->event('created')
            ->log(sprintf('Affaire « %s » créée.', $affaire->titre));

        return response()->json($affaire->load($this->resume()), 201);
    }

    /**
     * Fiche d'une affaire avec son historique d'etapes.
     *
     * @param Request $request
     * @param int $opportunite
     * @return JsonResponse
     */
    public function show(Request $request, int $opportunite): JsonResponse
    {
        $affaire = $this->perimetre($request)->findOrFail($opportunite);

        return response()->json($affaire->load([
            'etape', 'client', 'contact', 'adresseChantier', 'source', 'commercial:id,name', 'motifPerte',
            'historique.etapeVers',
        ]));
    }

    /**
     * Modifie une affaire (hors client et etape). Une affaire cloturee ne
     * garde que ses notes et le concurrent modifiables : pour la reprendre,
     * on la rouvre par un changement d'etape.
     *
     * @param UpdateOpportuniteRequest $request
     * @param int $opportunite
     * @return JsonResponse
     *
     * @throws ValidationException Si une affaire ouverte perd sa prochaine action.
     */
    public function update(UpdateOpportuniteRequest $request, int $opportunite): JsonResponse
    {
        $affaire = $this->perimetre($request)->with('etape')->findOrFail($opportunite);
        $donnees = $request->validated();
        $ouverte = $affaire->etape->type === EtapePipeline::TYPE_OUVERTE;

        if (! $ouverte) {
            $donnees = Arr::only($donnees, ['notes', 'concurrent']);
        }
        if (! $request->user()->can('opportunites.voir_toutes')) {
            unset($donnees['commercial_id']);
        }

        $affaire->fill($donnees);

        if ($ouverte && (blank($affaire->prochaine_action) || blank($affaire->prochaine_action_le))) {
            throw ValidationException::withMessages([
                'prochaine_action' => 'Une affaire ouverte garde sa prochaine action et sa date.',
            ]);
        }

        $affaire->save();

        return response()->json($affaire->load($this->resume()));
    }

    /**
     * Archive l'affaire (suppression douce). Refusee si un devis y est
     * rattache : le devis garde sa trace.
     *
     * @param Request $request
     * @param int $opportunite
     * @return JsonResponse
     *
     * @throws ValidationException Si l'affaire a des devis.
     */
    public function destroy(Request $request, int $opportunite): JsonResponse
    {
        $affaire = $this->perimetre($request)->findOrFail($opportunite);

        if (DB::table('quotes')->where('opportunity_id', $affaire->getKey())->exists()) {
            throw ValidationException::withMessages([
                'opportunite' => 'Des devis sont rattachés à cette affaire : classez-la sans suite plutôt que la supprimer.',
            ]);
        }

        $affaire->delete();

        activity()
            ->performedOn($affaire)
            ->causedBy($request->user())
            ->event('deleted')
            ->log(sprintf('Affaire « %s » supprimée.', $affaire->titre));

        return response()->json(null, 204);
    }

    /**
     * Change l'etape a la main (voir ChangementEtape pour les regles).
     *
     * @param ChangerEtapeRequest $request
     * @param ChangementEtape $changement
     * @param int $opportunite
     * @return JsonResponse
     */
    public function changerEtape(ChangerEtapeRequest $request, ChangementEtape $changement, int $opportunite): JsonResponse
    {
        $affaire = $this->perimetre($request)->with('etape')->findOrFail($opportunite);
        $vers = EtapePipeline::query()->findOrFail($request->integer('etape_id'));

        $changement->appliquer($affaire, $vers, $request->user(), $request->safe()->except('etape_id'));

        return response()->json($affaire->refresh()->load($this->resume()));
    }

    /**
     * Affaires visibles par l'utilisateur.
     *
     * @param Request $request
     * @return Builder<Opportunite>
     */
    private function perimetre(Request $request): Builder
    {
        $requete = Opportunite::query();

        if (! $request->user()->can('opportunites.voir_toutes')) {
            $requete->where('commercial_id', $request->user()->getKey());
        }

        return $requete;
    }

    /**
     * Relations chargees dans les listes et les reponses.
     *
     * @return array<int, string>
     */
    private function resume(): array
    {
        return ['etape', 'client', 'commercial:id,name'];
    }
}
