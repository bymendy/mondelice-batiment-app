<?php

namespace App\Http\Controllers;

use App\Http\Requests\LotDevisRequest;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\LotDevis;
use App\Services\Devis\AccesDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * Lots et emplacements d'un devis (regroupements de lignes).
 */
class LotDevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:devis.modifier')];
    }

    /**
     * @param LotDevisRequest $request
     * @param int $devis
     * @return JsonResponse
     */
    public function store(LotDevisRequest $request, int $devis): JsonResponse
    {
        $fiche = $this->devisModifiable($request, $devis);
        $donnees = $request->validated();

        $donnees['ordre'] ??= ((int) LotDevis::query()->where('devis_id', $fiche->getKey())->max('ordre')) + 1;
        $lot = LotDevis::create(array_merge($donnees, ['devis_id' => $fiche->getKey()]));

        return response()->json($lot->refresh(), 201);
    }

    /**
     * Renomme ou reordonne ; le type ne change pas.
     *
     * @param LotDevisRequest $request
     * @param int $lot
     * @return JsonResponse
     */
    public function update(LotDevisRequest $request, int $lot): JsonResponse
    {
        $element = LotDevis::query()->findOrFail($lot);
        $this->devisModifiable($request, $element->devis_id);

        $element->fill($request->validated())->save();

        return response()->json($element->refresh());
    }

    /**
     * Supprime un lot vide ; un lot qui contient des lignes se vide d'abord.
     *
     * @param Request $request
     * @param int $lot
     * @return JsonResponse
     *
     * @throws ValidationException Si le lot contient des lignes.
     */
    public function destroy(Request $request, int $lot): JsonResponse
    {
        $element = LotDevis::query()->findOrFail($lot);
        $this->devisModifiable($request, $element->devis_id);

        $utilise = LigneDevis::query()
            ->where(fn ($q) => $q->where('lot_id', $element->getKey())->orWhere('emplacement_id', $element->getKey()))
            ->exists();
        if ($utilise) {
            throw ValidationException::withMessages([
                'lot' => 'Ce lot contient des lignes : déplacez-les ou supprimez-les d\'abord.',
            ]);
        }

        $element->delete();

        return response()->json(null, 204);
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
}
