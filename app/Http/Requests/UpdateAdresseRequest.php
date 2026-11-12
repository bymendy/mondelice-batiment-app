<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de mise a jour d'une adresse. Le type (facturation / chantier)
 * ne change pas : on supprime l'adresse et on en cree une autre.
 */
class UpdateAdresseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['nullable', 'string', 'max:100'],
            'ligne1' => ['sometimes', 'required', 'string', 'max:255'],
            'ligne2' => ['nullable', 'string', 'max:255'],
            'code_postal' => ['sometimes', 'required', 'string', 'max:10'],
            'ville' => ['sometimes', 'required', 'string', 'max:100'],
            'pays' => ['sometimes', 'required', 'string', 'max:60'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'infos_acces' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
