<?php

namespace App\Http\Controllers;

use App\Services\Devis\AccesDevis;
use App\Services\Devis\ParametresDevis;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Paramètres d'un devis : lecture (devis.voir) et modification
 * (devis.modifier_parametres : Direction, Commercial).
 *
 * Une modification ne touche que les lignes ajoutées ensuite. Elle est
 * journalisée (`parametres_devis_modifies`, anciennes et nouvelles valeurs).
 */
class ParametresDevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:devis.voir', only: ['show']),
            new Middleware('can:devis.modifier_parametres', only: ['update']),
        ];
    }

    /**
     * @param Request $request
     * @param int $devis
     * @param ParametresDevis $service
     * @return JsonResponse
     */
    public function show(Request $request, int $devis, ParametresDevis $service): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);

        return response()->json($service->presenter($fiche, $request->user()));
    }

    /**
     * Champs facultatifs : `coefficient_difficulte` (1,00 à 1,50 par pas de
     * 0,05), `tva_defaut`, `taux_horaires` (liste de `{code, taux_vente_ht}`),
     * `coefficient_zone` (accepté seulement à 1 : les zones ne sont pas activées).
     *
     * @param Request $request
     * @param int $devis
     * @param ParametresDevis $service
     * @return JsonResponse
     */
    public function update(Request $request, int $devis, ParametresDevis $service): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);
        AccesDevis::exigerModifiable($fiche);

        $donnees = $request->validate([
            'coefficient_difficulte' => [
                'sometimes', 'numeric',
                'between:' . ParametresDevis::DIFFICULTE_MIN . ',' . ParametresDevis::DIFFICULTE_MAX,
                function (string $attribut, mixed $valeur, Closure $echec): void {
                    $pas = (float) $valeur / ParametresDevis::DIFFICULTE_PAS;
                    if (abs($pas - round($pas)) > 0.0001) {
                        $echec('Le coefficient de difficulté avance par pas de 0,05 (1,00 ; 1,05 ; 1,10...).');
                    }
                },
            ],
            'coefficient_zone' => [
                'sometimes', 'numeric',
                function (string $attribut, mixed $valeur, Closure $echec): void {
                    if (abs((float) $valeur - 1) > 0.0001) {
                        $echec('Le coefficient de zone n\'est pas encore activé : il reste à 1.');
                    }
                },
            ],
            'tva_defaut' => ['sometimes', 'numeric', 'between:0,100'],
            'taux_horaires' => ['sometimes', 'array', 'max:100'],
            'taux_horaires.*.code' => ['required', 'string', 'max:30'],
            'taux_horaires.*.taux_vente_ht' => ['required', 'numeric', 'between:0.01,9999.99'],
        ], [
            'coefficient_difficulte.between' => 'Le coefficient de difficulté va de 1,00 à 1,50.',
        ]);

        $changement = $service->modifier($fiche, $donnees);

        if ($changement['avant'] !== []) {
            activity()
                ->performedOn($fiche)
                ->causedBy($request->user())
                ->event('parametres_devis_modifies')
                ->withProperties($changement)
                ->log(sprintf('Paramètres du devis %s modifiés.', $fiche->numero));
        }

        return response()->json($service->presenter($fiche, $request->user()));
    }
}
