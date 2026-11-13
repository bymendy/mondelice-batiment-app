<?php

namespace App\Http\Controllers;

use App\Models\EtapePipeline;
use App\Models\Opportunite;
use App\Services\Crm\ChangementEtape;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Vue Pipeline (Kanban) : les affaires groupees par etape.
 *
 * Meme perimetre que la liste des affaires : la Direction
 * (opportunites.voir_toutes) voit tout et peut filtrer par commercial ; les
 * autres ne voient que leurs affaires.
 *
 * Le chiffre d'affaires pondere est le montant estime multiplie par la
 * probabilite de l'affaire. Les affaires sans montant comptent pour 0.
 */
class PipelineController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:opportunites.voir')];
    }

    /**
     * Colonnes du Kanban, dans l'ordre des etapes.
     *
     * Query params :
     *  - commercial_id=...  (Direction seulement ; ignore pour les autres)
     *  - source_id=...
     *  - du=AAAA-MM-JJ  au=AAAA-MM-JJ   periode de creation de l'affaire
     *  - limite=...  nombre maximum d'affaires par colonne (defaut 50, maximum 200)
     *
     * Chaque colonne donne le nombre total d'affaires, le montant, le chiffre
     * d'affaires pondere et au plus `limite` affaires (les plus urgentes
     * d'abord pour les etapes ouvertes, les plus recentes pour les autres).
     * `deplacable` indique si l'etape peut etre choisie a la main.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function kanban(Request $request): JsonResponse
    {
        $request->validate([
            'commercial_id' => ['sometimes', 'nullable', 'integer'],
            'source_id' => ['sometimes', 'nullable', 'integer'],
            'du' => ['sometimes', 'nullable', 'date'],
            'au' => ['sometimes', 'nullable', 'date'],
            'limite' => ['sometimes', 'integer', 'between:1,200'],
        ]);

        $limite = $request->integer('limite', 50);
        $base = $this->filtrer($request);
        $aujourdhui = now()->toDateString();

        $totaux = (clone $base)
            ->selectRaw('etape_id, count(*) as nombre, coalesce(sum(montant_estime), 0) as montant, coalesce(sum(montant_estime * probabilite / 100.0), 0) as pondere')
            ->groupBy('etape_id')
            ->get()
            ->keyBy('etape_id');

        $colonnes = [];
        $ouvertes = ['nombre' => 0, 'montant' => 0.0, 'ca_pondere' => 0.0];

        foreach (EtapePipeline::query()->orderBy('ordre')->get() as $etape) {
            $ouverte = $etape->type === EtapePipeline::TYPE_OUVERTE;
            $total = $totaux->get($etape->getKey());
            $nombre = (int) ($total->nombre ?? 0);
            $montant = round((float) ($total->montant ?? 0), 2);
            $pondere = round((float) ($total->pondere ?? 0), 2);

            $requete = (clone $base)->where('etape_id', $etape->getKey())->with(['client', 'commercial:id,name']);
            $ordre = $ouverte
                ? $requete->orderByRaw('prochaine_action_le asc nulls last')->orderByDesc('id')
                : $requete->orderByDesc('id');

            $affaires = $ordre->limit($limite)->get()->each(function (Opportunite $affaire) use ($ouverte, $aujourdhui): void {
                $affaire->setAttribute('alerte', $ouverte ? $this->alerte($affaire, $aujourdhui) : null);
            });

            if ($ouverte) {
                $ouvertes['nombre'] += $nombre;
                $ouvertes['montant'] += $montant;
                $ouvertes['ca_pondere'] += $pondere;
            }

            $colonnes[] = [
                'id' => $etape->getKey(),
                'code' => $etape->code,
                'nom' => $etape->nom,
                'type' => $etape->type,
                'ordre' => $etape->ordre,
                'probabilite_defaut' => $etape->probabilite_defaut,
                'deplacable' => ChangementEtape::estManuelle($etape),
                'nombre' => $nombre,
                'montant_total' => $montant,
                'ca_pondere' => $pondere,
                'plus' => $nombre > $limite,
                'affaires' => $affaires,
            ];
        }

        $ouvertes['montant'] = round($ouvertes['montant'], 2);
        $ouvertes['ca_pondere'] = round($ouvertes['ca_pondere'], 2);

        $ouvertesSeules = fn () => (clone $base)->whereHas('etape', fn (Builder $q) => $q->where('type', EtapePipeline::TYPE_OUVERTE));

        return response()->json([
            'etapes' => $colonnes,
            'totaux' => [
                'ouvertes' => $ouvertes,
                'en_retard' => $ouvertesSeules()->whereDate('prochaine_action_le', '<', $aujourdhui)->count(),
                'sans_action' => $ouvertesSeules()->where(fn (Builder $q) => $q->whereNull('prochaine_action')->orWhereNull('prochaine_action_le'))->count(),
            ],
        ]);
    }

    /**
     * Affaires du perimetre de l'utilisateur, filtres appliques.
     *
     * @param Request $request
     * @return Builder<Opportunite>
     */
    private function filtrer(Request $request): Builder
    {
        $requete = Opportunite::query();
        $utilisateur = $request->user();

        if (! $utilisateur->can('opportunites.voir_toutes')) {
            $requete->where('commercial_id', $utilisateur->getKey());
        } elseif ($request->filled('commercial_id')) {
            $requete->where('commercial_id', $request->integer('commercial_id'));
        }

        if ($request->filled('source_id')) {
            $requete->where('source_id', $request->integer('source_id'));
        }
        if ($request->filled('du')) {
            $requete->whereDate('created_at', '>=', $request->date('du')->toDateString());
        }
        if ($request->filled('au')) {
            $requete->whereDate('created_at', '<=', $request->date('au')->toDateString());
        }

        return $requete;
    }

    /**
     * Alerte d'une affaire ouverte : « sans_action » (prochaine action ou date
     * manquante) ou « en_retard » (date depassee).
     *
     * @param Opportunite $affaire
     * @param string $aujourdhui
     * @return string|null
     */
    private function alerte(Opportunite $affaire, string $aujourdhui): ?string
    {
        if (blank($affaire->prochaine_action) || $affaire->prochaine_action_le === null) {
            return 'sans_action';
        }

        return $affaire->prochaine_action_le->toDateString() < $aujourdhui ? 'en_retard' : null;
    }
}
