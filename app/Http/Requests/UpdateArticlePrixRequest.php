<?php

namespace App\Http\Requests;

use App\Enums\CategorieArticle;
use App\Enums\UniteArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation de mise a jour d'un article de la Bibliotheque de prix
 * (tache 3.3). Tous les champs sont "sometimes" : une mise a jour peut
 * ne porter que sur un seul champ (ex. juste le prix de vente).
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
            'categorie' => ['sometimes', 'required', new Enum(CategorieArticle::class)],
            'famille' => ['nullable', 'string', 'max:100'],
            'sous_famille' => ['nullable', 'string', 'max:100'],
            'unite' => ['sometimes', 'required', new Enum(UniteArticle::class)],
            'prix_achat_ht' => ['nullable', 'numeric', 'min:0'],
            'prix_vente_ht' => ['sometimes', 'required', 'numeric', 'min:0'],
            'taux_tva' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'fournisseur' => ['nullable', 'string', 'max:255'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
