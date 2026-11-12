<?php

namespace App\Http\Requests;

use App\Enums\TypeAdresse;
use App\Models\Adresse;
use App\Models\Client;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation de creation d'une adresse de client. Une seule adresse de
 * facturation par client. L'autorisation est geree par le middleware
 * "can:clients.modifier" du controleur.
 */
class StoreAdresseRequest extends FormRequest
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
            'type' => ['required', new Enum(TypeAdresse::class), $this->uneSeuleFacturation()],
            'libelle' => ['nullable', 'string', 'max:100'],
            'ligne1' => ['required', 'string', 'max:255'],
            'ligne2' => ['nullable', 'string', 'max:255'],
            'code_postal' => ['required', 'string', 'max:10'],
            'ville' => ['required', 'string', 'max:100'],
            'pays' => ['nullable', 'string', 'max:60'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'infos_acces' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Refuse une deuxieme adresse de facturation pour le meme client.
     *
     * @return Closure
     */
    private function uneSeuleFacturation(): Closure
    {
        $client = $this->route('client');

        return function (string $attribute, mixed $valeur, Closure $echec) use ($client): void {
            if (
                $valeur === TypeAdresse::Facturation->value
                && $client instanceof Client
                && Adresse::query()->where('client_id', $client->getKey())->where('type', TypeAdresse::Facturation->value)->exists()
            ) {
                $echec('Ce client a deja une adresse de facturation : modifiez-la plutot.');
            }
        };
    }
}
