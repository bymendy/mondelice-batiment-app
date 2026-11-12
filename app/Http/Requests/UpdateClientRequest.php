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
 * Validation de mise a jour d'un client. Les champs conditionnels (nom /
 * raison sociale) ne sont exiges que si le type est renvoye dans la requete.
 */
class UpdateClientRequest extends FormRequest
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
            'type' => ['sometimes', 'required', new Enum(TypeClient::class)],
            'nom' => ['required_if:type,particulier', 'nullable', 'string', 'max:255'],
            'prenom' => ['nullable', 'string', 'max:255'],
            'raison_sociale' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), TypeClient::avecRaisonSociale(), true)),
                'nullable', 'string', 'max:255',
            ],
            'siret' => ['nullable', 'digits:14', $this->siretUnique()],
            'tva_intra' => ['nullable', 'string', 'max:20'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'source_lead' => ['nullable', new Enum(SourceLead::class)],
            'ne_plus_demarcher_le' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Regle d'unicite du SIRET dans l'entreprise courante, en ignorant le
     * client modifie.
     *
     * @return Closure
     */
    private function siretUnique(): Closure
    {
        $courant = $this->route('client');
        $ignorer = $courant instanceof Client ? $courant->getKey() : null;

        return function (string $attribute, mixed $valeur, Closure $echec) use ($ignorer): void {
            if ($valeur === null) {
                return;
            }

            $existe = Client::query()
                ->where('siret', $valeur)
                ->when($ignorer !== null, fn ($q) => $q->whereKeyNot($ignorer))
                ->exists();

            if ($existe) {
                $echec('Ce SIRET est deja utilise par un autre client.');
            }
        };
    }
}
