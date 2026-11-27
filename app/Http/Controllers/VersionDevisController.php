<?php

namespace App\Http\Controllers;

use App\Services\Devis\AccesDevis;
use App\Services\Devis\StatutsDevis;
use App\Services\Devis\VersionsDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Nouvelle version d'un devis (lot V1). Permission : devis.creer (Direction,
 * Commercial), comme pour créer un devis.
 */
class VersionDevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:devis.creer')];
    }

    /**
     * Champs facultatifs : `mode` (`remplace` par défaut : la version copiée
     * passe à « Non retenue » ; `en_plus` : les deux restent proposées),
     * `titre`, `notes`, `validite_jours`.
     *
     * @param Request $request
     * @param int $devis Version à copier.
     * @param VersionsDevis $service
     * @return JsonResponse
     */
    public function store(Request $request, int $devis, VersionsDevis $service): JsonResponse
    {
        $source = AccesDevis::visibles($request->user())->findOrFail($devis);

        $donnees = $request->validate([
            'mode' => ['sometimes', Rule::in([VersionsDevis::MODE_REMPLACE, VersionsDevis::MODE_EN_PLUS])],
            'titre' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'validite_jours' => ['sometimes', 'integer', 'between:1,365'],
        ]);
        $mode = $donnees['mode'] ?? VersionsDevis::MODE_REMPLACE;
        unset($donnees['mode']);

        $version = $service->creer($source, $request->user(), $mode, $donnees);
        $source->refresh();

        return response()->json(array_merge($version->toArray(), [
            'statut_libelle' => StatutsDevis::libelle((string) $version->statut),
            'macro_etat' => StatutsDevis::macroEtat((string) $version->statut),
            'mode' => $mode,
            'version_source' => [
                'id' => $source->getKey(),
                'numero' => $source->numero,
                'statut' => (string) $source->statut,
                'statut_libelle' => StatutsDevis::libelle((string) $source->statut),
            ],
        ], VersionsDevis::pour($version, $request->user())), 201);
    }
}
