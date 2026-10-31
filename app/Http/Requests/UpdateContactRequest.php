<?php

namespace App\Http\Requests;

use App\Enums\SourceLead;
use App\Enums\TypeContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation de mise a jour d'un contact (tache 3.4). Les champs
 * conditionnels (nom / raison sociale) ne sont exigés que si le type est
 * renvoye dans la requete.
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
            'type' => ['sometimes', 'required', new Enum(TypeContact::class)],
            'nom' => ['required_if:type,particulier', 'nullable', 'string', 'max:255'],
            'prenom' => ['nullable', 'string', 'max:255'],
            'raison_sociale' => ['required_if:type,professionnel', 'nullable', 'string', 'max:255'],
            'siret' => ['nullable', 'digits:14'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'source_lead' => ['nullable', new Enum(SourceLead::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
