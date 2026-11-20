<?php

namespace App\Http\Controllers;

use App\Models\Devis;
use App\Services\Devis\AccesDevis;
use App\Services\Devis\RecalculDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * « Recalculer le devis » et « Appliquer la TVA à toutes les lignes »
 * (permission devis.modifier_parametres : Direction, Commercial).
 */
class RecalculDevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:devis.modifier_parametres')];
    }

    /**
     * Champs facultatifs : `simulation` (compter sans écrire, pour le message
     * de confirmation), `inclure_prix_forces` (recalculer aussi les lignes à
     * prix forcé).
     *
     * @param Request $request
     * @param int $devis
     * @param RecalculDevis $service
     * @return JsonResponse
     */
    public function recalculer(Request $request, int $devis, RecalculDevis $service): JsonResponse
    {
        $fiche = $this->devisModifiable($request, $devis);
        $donnees = $request->validate([
            'simulation' => ['sometimes', 'boolean'],
            'inclure_prix_forces' => ['sometimes', 'boolean'],
        ]);
        $simulation = (bool) ($donnees['simulation'] ?? false);

        $resultat = $service->executer($fiche, $request->user(), (bool) ($donnees['inclure_prix_forces'] ?? false), $simulation);

        if (! $simulation) {
            activity()
                ->performedOn($fiche)
                ->causedBy($request->user())
                ->event('devis_recalcule')
                ->withProperties($resultat)
                ->log(sprintf('Devis %s recalculé : %s', $fiche->numero, $resultat['message']));
        }

        return response()->json(array_merge($resultat, ['totaux_devis' => $this->totaux($fiche->refresh())]));
    }

    /**
     * @param Request $request
     * @param int $devis
     * @param RecalculDevis $service
     * @return JsonResponse
     */
    public function appliquerTva(Request $request, int $devis, RecalculDevis $service): JsonResponse
    {
        $fiche = $this->devisModifiable($request, $devis);
        $resultat = $service->appliquerTva($fiche);

        if ($resultat['lignes_modifiees'] > 0) {
            activity()
                ->performedOn($fiche)
                ->causedBy($request->user())
                ->event('tva_appliquee')
                ->withProperties($resultat)
                ->log(sprintf('TVA de %s %% appliquée à toutes les lignes du devis %s.', rtrim(rtrim(number_format($resultat['taux_tva'], 2, ',', ''), '0'), ','), $fiche->numero));
        }

        return response()->json(array_merge($resultat, ['totaux_devis' => $this->totaux($fiche->refresh())]));
    }

    /**
     * @param Request $request
     * @param int $devis
     * @return Devis
     */
    private function devisModifiable(Request $request, int $devis): Devis
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);
        AccesDevis::exigerModifiable($fiche);

        return $fiche;
    }

    /**
     * @param Devis $devis
     * @return array<string, mixed>
     */
    private function totaux(Devis $devis): array
    {
        return [
            'total_ht' => $devis->total_ht,
            'total_tva' => $devis->total_tva,
            'total_ttc' => $devis->total_ttc,
        ];
    }
}
