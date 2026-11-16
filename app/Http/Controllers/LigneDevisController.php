<?php

namespace App\Http\Controllers;

use App\Http\Requests\LigneDevisRequest;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Services\Devis\AccesDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Lignes d'un devis. Chaque ecriture recalcule les totaux du devis, qui sont
 * renvoyes avec la ligne.
 */
class LigneDevisController extends Controller implements HasMiddleware
{
    /**
     * Taux de TVA applique quand la ligne n'en donne pas (provisoire : le taux
     * par defaut de l'entreprise sera un reglage).
     */
    private const TVA_PAR_DEFAUT = 20;

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:devis.modifier')];
    }

    /**
     * @param LigneDevisRequest $request
     * @param int $devis
     * @return JsonResponse
     */
    public function store(LigneDevisRequest $request, int $devis): JsonResponse
    {
        $fiche = $this->devisModifiable($request, $devis);
        $donnees = $request->validated();

        $donnees['taux_tva'] ??= self::TVA_PAR_DEFAUT;
        $donnees['ordre'] ??= ((int) LigneDevis::query()->where('devis_id', $fiche->getKey())->max('ordre')) + 1;

        $ligne = LigneDevis::create(array_merge($donnees, ['devis_id' => $fiche->getKey()]));
        $fiche->recalculerTotaux();
        $this->journaliser($request, $fiche, sprintf('Ligne « %s » ajoutée.', $ligne->libelle));

        return response()->json($this->reponse($ligne, $fiche), 201);
    }

    /**
     * @param LigneDevisRequest $request
     * @param int $ligne
     * @return JsonResponse
     */
    public function update(LigneDevisRequest $request, int $ligne): JsonResponse
    {
        $element = LigneDevis::query()->findOrFail($ligne);
        $fiche = $this->devisModifiable($request, $element->devis_id);

        $element->fill($request->validated())->save();
        $fiche->recalculerTotaux();
        $this->journaliser($request, $fiche, sprintf('Ligne « %s » modifiée.', $element->libelle));

        return response()->json($this->reponse($element, $fiche));
    }

    /**
     * @param Request $request
     * @param int $ligne
     * @return JsonResponse
     */
    public function destroy(Request $request, int $ligne): JsonResponse
    {
        $element = LigneDevis::query()->findOrFail($ligne);
        $fiche = $this->devisModifiable($request, $element->devis_id);

        $element->delete();
        $fiche->recalculerTotaux();
        $this->journaliser($request, $fiche, sprintf('Ligne « %s » supprimée.', $element->libelle));

        return response()->json(['totaux_devis' => $this->totaux($fiche)]);
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
     * @param LigneDevis $ligne
     * @param Devis $devis
     * @return array<string, mixed>
     */
    private function reponse(LigneDevis $ligne, Devis $devis): array
    {
        return array_merge($ligne->refresh()->toArray(), ['totaux_devis' => $this->totaux($devis)]);
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

    /**
     * @param Request $request
     * @param Devis $devis
     * @param string $message
     * @return void
     */
    private function journaliser(Request $request, Devis $devis, string $message): void
    {
        activity()
            ->performedOn($devis)
            ->causedBy($request->user())
            ->event('updated')
            ->log($message);
    }
}
