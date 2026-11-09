<?php

namespace App\Http\Requests;

use App\Enums\SourceLead;
use App\Enums\TypeClient;
use App\Models\Client;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation de creation d'un client.
 *
 * Particulier : nom obligatoire. Autres types : raison sociale obligatoire.
 * Le SIRET est unique dans l'entreprise (hors clients archives). L'autorisation
 * est geree par le middleware "can:clients.creer" du controleur.
 */
class StoreClientRequest extends FormRequest
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
            'type' => ['required', new Enum(TypeClient::class)],
            'nom' => ['required_if:type,particulier', 'nullable', 'string', 'max:255'],
            'prenom' => ['nullable', 'string', 'max:255'],
            'raison_sociale' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), TypeClient::avecRaisonSociale(), true)),
                'nullable', 'string', 'max:255',
            ],
            'siret' => ['nullable', 'digits:14', $this->siretUnique()],
            'tva_intra' => ['nullable', 'string', 'max:20'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'source_lead' => ['nullable', new Enum(SourceLead::class)],
            'ne_plus_demarcher_le' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Regle d'unicite du SIRET dans l'entreprise courante. Passe par le
     * modele pour reutiliser le filtre tenant et l'exclusion des archives.
     *
     * @return Closure
     */
    private function siretUnique(): Closure
    {
        return function (string $attribute, mixed $valeur, Closure $echec): void {
            if ($valeur !== null && Client::query()->where('siret', $valeur)->exists()) {
                $echec('Ce SIRET est deja utilise par un autre client.');
            }
        };
    }
}
