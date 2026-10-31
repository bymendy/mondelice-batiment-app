<?php

namespace App\Http\Requests;

use App\Enums\CategorieArticle;
use App\Enums\UniteArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation de creation d'un article de la Bibliotheque de prix (tache 3.3).
 *
 * L'autorisation (qui a le droit de creer un article) passe par les roles
 * et permissions Spatie poses en Phase 2 -- voir ArticlePrixController et
 * le middleware de permission sur la route.
 */
class StoreArticlePrixRequest extends FormRequest
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
                'required', 'string', 'max:50',
                Rule::unique('articles_prix', 'reference')->where('tenant_id', tenant_id()),
            ],
            'designation' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'categorie' => ['required', new Enum(CategorieArticle::class)],
            'famille' => ['nullable', 'string', 'max:100'],
            'sous_famille' => ['nullable', 'string', 'max:100'],
            'unite' => ['required', new Enum(UniteArticle::class)],
            'prix_achat_ht' => ['nullable', 'numeric', 'min:0'],
            'prix_vente_ht' => ['required', 'numeric', 'min:0'],
            'taux_tva' => ['required', 'numeric', 'min:0', 'max:100'],
            'fournisseur' => ['nullable', 'string', 'max:255'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
