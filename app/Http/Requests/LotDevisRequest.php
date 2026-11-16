<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creation (POST) et modification (PUT) d'un lot ou d'un emplacement.
 * Le type se choisit a la creation et ne change plus.
 */
class LotDevisRequest extends FormRequest
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

        return [
            'type' => $creation ? ['required', 'in:lot,emplacement'] : ['prohibited'],
            'libelle' => $creation ? ['required', 'string', 'max:255'] : ['sometimes', 'string', 'max:255'],
            'ordre' => ['sometimes', 'integer', 'between:0,100000'],
        ];
    }
}
