<?php

namespace App\Http\Requests;

use App\Models\Adresse;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Opportunite;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creation d'un devis. Les controles « meme entreprise » passent par les
 * modeles, qui ne voient que l'entreprise courante.
 */
class StoreDevisRequest extends FormRequest
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
        return $this->regles(true);
    }

    /**
     * @param bool $creation Vrai a la creation, faux a la modification.
     * @return array<string, mixed>
     */
    protected function regles(bool $creation): array
    {
        $facultatif = ['sometimes', 'nullable'];

        $regles = [
            'titre' => $creation ? ['required', 'string', 'max:200'] : ['sometimes', 'string', 'max:200'],
            'portee' => ['sometimes', 'in:prive,entreprise'],
            'date_devis' => ['sometimes', 'date'],
            'validite_jours' => ['sometimes', 'integer', 'between:1,365'],
            'notes' => array_merge($facultatif, ['string', 'max:5000']),
            'opportunite_id' => array_merge($facultatif, ['integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                $client = $this->clientCible();
                $utilisateur = $this->user();
                $existe = $client !== null && Opportunite::query()
                    ->whereKey($valeur)
                    ->where('client_id', $client)
                    ->when(! $utilisateur->can('opportunites.voir_toutes'), fn ($q) => $q->where('commercial_id', $utilisateur->getKey()))
                    ->exists();
                if (! $existe) {
                    $echec('Cette affaire n\'existe pas pour ce client.');
                }
            }]),
            'adresse_chantier_id' => array_merge($facultatif, ['integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                $client = $this->clientCible();
                if ($client === null || ! Adresse::query()->whereKey($valeur)->where('client_id', $client)->where('type', 'chantier')->exists()) {
                    $echec('Cette adresse de chantier n\'appartient pas à ce client.');
                }
            }]),
        ];

        if ($creation) {
            $regles['client_id'] = ['required', 'integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (! Client::query()->whereKey($valeur)->exists()) {
                    $echec('Ce client n\'existe pas.');
                }
            }];
        }

        return $regles;
    }

    /**
     * Client concerne : celui du formulaire (creation) ou celui du devis
     * (modification).
     *
     * @return int|null
     */
    protected function clientCible(): ?int
    {
        if ($this->isMethod('POST')) {
            return $this->filled('client_id') ? (int) $this->input('client_id') : null;
        }

        return Devis::query()->find((int) $this->route('devis'))?->client_id;
    }
}
