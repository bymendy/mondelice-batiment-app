<?php

namespace App\Http\Requests;

use App\Enums\TypeArticle;
use App\Enums\UniteArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation de mise a jour d'un article de la Bibliotheque de prix (taches
 * 3.3 et lot 3). Tous les champs sont "sometimes" : une mise a jour peut
 * ne porter que sur un seul champ (ex. juste le prix force). Envoyer
 * `prix_force_ht: null` retire le prix force (le prix redevient calcule).
 */
class UpdateArticlePrixRequest extends FormRequest
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
            'reference' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('articles_prix', 'reference')
                    ->where('tenant_id', tenant_id())
                    ->ignore($this->route('articlePrix')),
            ],
            'designation' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type_article' => ['sometimes', 'required', new Enum(TypeArticle::class)],
            'categorie_id' => ['nullable', 'integer', StoreArticlePrixRequest::regleCategorie()],
            'unite' => ['sometimes', 'required', new Enum(UniteArticle::class)],
            'prix_force_ht' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'taux_tva' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'fournisseur' => ['nullable', 'string', 'max:255'],
            'dtu_reference' => ['nullable', 'string', 'max:120'],
            'dtu_lien' => ['nullable', 'url:http,https', 'max:500'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
