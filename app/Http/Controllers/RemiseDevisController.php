<?php

namespace App\Http\Controllers;

use App\Http\Requests\RemiseDevisRequest;
use App\Models\DeductionDevis;
use App\Models\Devis;
use App\Services\Devis\AccesDevis;
use App\Services\Devis\PresentationDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remises par lot et globales d'un devis (la remise par ligne se regle sur la
 * ligne). Elles ne se modifient que sur un brouillon ; chaque ecriture
 * recalcule les totaux et est journalisee.
 */
class RemiseDevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:devis.modifier')];
    }

    /**
     * @param RemiseDevisRequest $request
     * @param int $devis
     * @return JsonResponse
     *
     * @throws ValidationException Si le devis n'est plus modifiable ou si la remise depasse sa base.
     */
    public function store(RemiseDevisRequest $request, int $devis): JsonResponse
    {
        $fiche = $this->devisModifiable($request, $devis);
        $donnees = $request->validated();
        $donnees['ordre'] ??= ((int) DeductionDevis::query()->where('devis_id', $fiche->getKey())->max('ordre')) + 1;

        [$remise, $detail] = DB::transaction(function () use ($fiche, $donnees): array {
            $remise = DeductionDevis::create(array_merge($donnees, ['devis_id' => $fiche->getKey()]));

            return [$remise, $this->recalculer($fiche, $remise)];
        });

        $this->journaliser($request, $fiche, sprintf('Remise « %s » ajoutée.', $remise->libelle), $remise);

        return response()->json($this->reponse($request, $remise, $fiche, $detail), 201);
    }

    /**
     * @param RemiseDevisRequest $request
     * @param int $remise
     * @return JsonResponse
     *
     * @throws ValidationException Si le devis n'est plus modifiable ou si la remise depasse sa base.
     */
    public function update(RemiseDevisRequest $request, int $remise): JsonResponse
    {
        $element = DeductionDevis::query()->findOrFail($remise);
        $fiche = $this->devisModifiable($request, $element->devis_id);

        $detail = DB::transaction(function () use ($element, $fiche, $request): array {
            $element->fill($request->validated())->save();

            return $this->recalculer($fiche, $element);
        });

        $this->journaliser($request, $fiche, sprintf('Remise « %s » modifiée.', $element->libelle), $element);

        return response()->json($this->reponse($request, $element, $fiche, $detail));
    }

    /**
     * @param Request $request
     * @param int $remise
     * @return JsonResponse
     *
     * @throws ValidationException Si le devis n'est plus modifiable.
     */
    public function destroy(Request $request, int $remise): JsonResponse
    {
        $element = DeductionDevis::query()->findOrFail($remise);
        $fiche = $this->devisModifiable($request, $element->devis_id);

        $element->delete();
        $detail = $fiche->recalculerTotaux();

        $this->journaliser($request, $fiche, sprintf('Remise « %s » supprimée.', $element->libelle), $element);

        return response()->json([
            'totaux_devis' => $this->totaux($fiche),
            'detail_totaux' => PresentationDevis::totaux($detail, $request->user()),
        ]);
    }

    /**
     * Recalcule les totaux et refuse une remise qui depasse sa base.
     *
     * @param Devis $devis
     * @param DeductionDevis $remise
     * @return array<string, mixed> Detail du calcul.
     *
     * @throws ValidationException
     */
    private function recalculer(Devis $devis, DeductionDevis $remise): array
    {
        $detail = $devis->recalculerTotaux();

        foreach ($detail['remises']['detail'] as $ligne) {
            if ($ligne['id'] === $remise->getKey() && $ligne['plafonnee']) {
                throw ValidationException::withMessages([
                    'valeur' => sprintf(
                        'La remise dépasse le montant sur lequel elle porte (%s € HT).',
                        number_format($ligne['base_ht'], 2, ',', ' ')
                    ),
                ]);
            }
        }

        return $detail;
    }

    /**
     * @param Request $request
     * @param int $devis
     * @return Devis
     *
     * @throws ValidationException Si le devis n'est plus un brouillon.
     */
    private function devisModifiable(Request $request, int $devis): Devis
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);
        AccesDevis::exigerModifiable($fiche);

        return $fiche;
    }

    /**
     * @param Request $request
     * @param DeductionDevis $remise
     * @param Devis $devis
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function reponse(Request $request, DeductionDevis $remise, Devis $devis, array $detail): array
    {
        return array_merge($remise->refresh()->toArray(), [
            'totaux_devis' => $this->totaux($devis),
            'detail_totaux' => PresentationDevis::totaux($detail, $request->user()),
        ]);
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
     * @param DeductionDevis $remise
     * @return void
     */
    private function journaliser(Request $request, Devis $devis, string $message, DeductionDevis $remise): void
    {
        activity()
            ->performedOn($devis)
            ->causedBy($request->user())
            ->event('updated')
            ->withProperties([
                'remise_id' => $remise->getKey(),
                'lot_id' => $remise->lot_id,
                'type' => $remise->type,
                'valeur' => (float) $remise->valeur,
            ])
            ->log($message);
    }
}
