<?php

namespace App\Http\Requests;

use App\Models\DeductionDevis;
use App\Models\LotDevis;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Remise par lot ou globale d'un devis.
 *
 * A la creation : libelle, type (pourcentage ou montant), valeur, lot facultatif.
 * A la modification : le lot ne change pas.
 */
class RemiseDevisRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creation = $this->isMethod('POST');
        $present = $creation ? ['required'] : ['sometimes'];

        return [
            'libelle' => array_merge($present, ['string', 'max:255']),
            'type' => array_merge($present, ['in:pourcentage,montant']),
            'valeur' => array_merge($present, ['numeric', 'gt:0', 'max:99999999.99']),
            'ordre' => ['sometimes', 'integer', 'between:0,100000'],
            'lot_id' => $creation
                ? ['sometimes', 'nullable', 'integer', $this->regleLot()]
                : ['prohibited'],
        ];
    }

    /**
     * Un pourcentage ne depasse pas 100, en tenant compte du type deja
     * enregistre quand seule la valeur est modifiee.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $existante = $this->isMethod('POST') ? null : DeductionDevis::query()->find((int) $this->route('remise'));
            $type = $this->input('type', $existante?->type);
            $valeur = $this->input('valeur', $existante?->valeur);

            if ($type === 'pourcentage' && (float) $valeur > 100) {
                $validator->errors()->add('valeur', 'Une remise en pourcentage ne dépasse pas 100 %.');
            }
        });
    }

    /**
     * Le lot doit etre un lot (pas un emplacement) de ce devis.
     *
     * @return Closure
     */
    private function regleLot(): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec): void {
            $existe = LotDevis::query()
                ->whereKey($valeur)
                ->where('devis_id', (int) $this->route('devis'))
                ->where('type', LotDevis::TYPE_LOT)
                ->exists();

            if (! $existe) {
                $echec('Ce lot n\'appartient pas à ce devis.');
            }
        };
    }
}
