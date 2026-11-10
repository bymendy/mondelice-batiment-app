<?php

namespace App\Http\Requests;

use App\Models\ArticlePrix;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation du reordonnancement du sous-detail d'un article (Bibliotheque de
 * prix, lot 5) : la liste complete des identifiants de lignes, dans le nouvel
 * ordre. Le controle « toutes les lignes, ni plus ni moins » est fait par le
 * controleur.
 */
class ReordonnerComposantsRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var ArticlePrix $article */
        $article = $this->route('articlePrix');
        $article->verifierModifiablePar($this->user());

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
