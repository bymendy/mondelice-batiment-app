<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changement manuel de statut. « Envoyé » n'est pas permis ici : il a son
 * propre endpoint. Accepter exige une confirmation, un motif et une preuve ;
 * refuser et annuler exigent un motif.
 */
class ChangerStatutDevisRequest extends FormRequest
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
        $statut = $this->input('statut');

        return [
            'statut' => ['required', 'in:negociation,accepte,refuse,annule'],
            'motif' => [Rule::requiredIf(in_array($statut, ['accepte', 'refuse', 'annule'], true)), 'nullable', 'string', 'max:1000'],
            'confirmation' => ['accepted_if:statut,accepte'],
            'preuve_acceptation' => [Rule::requiredIf($statut === 'accepte'), 'nullable', 'string', 'max:2000'],
        ];
    }
}
