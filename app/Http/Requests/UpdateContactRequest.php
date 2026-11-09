<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de mise a jour d'un contact (personne a joindre).
 */
class UpdateContactRequest extends FormRequest
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
            'civilite' => ['nullable', 'string', 'max:10'],
            'prenom' => ['nullable', 'string', 'max:255'],
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'preference_contact' => ['nullable', 'in:email,telephone,mobile'],
            'ne_plus_demarcher_le' => ['nullable', 'date'],
        ];
    }
}
