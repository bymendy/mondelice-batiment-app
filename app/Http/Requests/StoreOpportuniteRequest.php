<?php

namespace App\Http\Requests;

use App\Models\Adresse;
use App\Models\Client;
use App\Models\Contact;
use App\Models\EtapePipeline;
use App\Models\Opportunite;
use App\Models\Source;
use App\Models\User;
use App\Services\Crm\ChangementEtape;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Creation d'une affaire. Les verifications « appartient a la meme
 * entreprise » passent par les modeles, qui ne voient que l'entreprise
 * courante : un identifiant d'une autre entreprise est donc refuse.
 */
class StoreOpportuniteRequest extends FormRequest
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
        $present = $creation ? ['required'] : ['sometimes'];
        $facultatif = ['sometimes', 'nullable'];

        $regles = [
            'titre' => array_merge($present, ['string', 'max:200']),
            'metiers' => array_merge($facultatif, ['array', 'max:20']),
            'metiers.*' => ['string', 'max:60'],
            'montant_estime' => array_merge($facultatif, ['numeric', 'min:0', 'max:99999999.99']),
            'probabilite' => array_merge($facultatif, ['integer', 'between:0,100']),
            'date_decision_prevue' => array_merge($facultatif, ['date']),
            'visite_prevue_le' => array_merge($facultatif, ['date']),
            'concurrent' => array_merge($facultatif, ['string', 'max:150']),
            'notes' => array_merge($facultatif, ['string', 'max:5000']),
            'prochaine_action' => $creation ? ['required', 'string', 'max:255'] : array_merge($facultatif, ['string', 'max:255']),
            'prochaine_action_le' => $creation ? ['required', 'date'] : array_merge($facultatif, ['date']),
            'source_id' => array_merge($facultatif, ['integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (! Source::query()->whereKey($valeur)->exists()) {
                    $echec('Cette source n\'existe pas.');
                }
            }]),
            'commercial_id' => array_merge($facultatif, ['integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (! User::query()->whereKey($valeur)->exists()) {
                    $echec('Ce commercial n\'existe pas dans votre entreprise.');
                }
            }]),
            'contact_id' => array_merge($facultatif, ['integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                $client = $this->clientCible();
                $rattache = $client !== null
                    && Contact::query()->whereKey($valeur)->exists()
                    && DB::table('client_contact')->where('client_id', $client)->where('contact_id', $valeur)->exists();
                if (! $rattache) {
                    $echec('Ce contact n\'est pas rattaché à ce client.');
                }
            }]),
            'adresse_chantier_id' => array_merge($facultatif, ['integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                $client = $this->clientCible();
                if ($client === null || ! Adresse::query()->whereKey($valeur)->where('client_id', $client)->exists()) {
                    $echec('Cette adresse n\'appartient pas à ce client.');
                }
            }]),
        ];

        if ($creation) {
            $regles['client_id'] = ['required', 'integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (! Client::query()->whereKey($valeur)->exists()) {
                    $echec('Ce client n\'existe pas.');
                }
            }];
            $regles['etape_id'] = ['sometimes', 'integer', function (string $attribut, mixed $valeur, Closure $echec): void {
                $etape = EtapePipeline::query()->whereKey($valeur)->first();
                if ($etape === null || $etape->type !== EtapePipeline::TYPE_OUVERTE || ! ChangementEtape::estManuelle($etape)) {
                    $echec('Une affaire commence à une étape ouverte qui se choisit à la main.');
                }
            }];
        }

        return $regles;
    }

    /**
     * Client concerne : celui du formulaire (creation) ou celui de l'affaire
     * (modification).
     *
     * @return int|null
     */
    protected function clientCible(): ?int
    {
        if ($this->isMethod('POST')) {
            return $this->filled('client_id') ? (int) $this->input('client_id') : null;
        }

        return Opportunite::query()->find((int) $this->route('opportunite'))?->client_id;
    }
}
