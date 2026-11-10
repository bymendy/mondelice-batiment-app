<?php

namespace App\Http\Requests;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la modification d'une ligne du sous-detail (Bibliotheque de
 * prix, lot 5). Le type d'une ligne ne change pas : pour en changer, on supprime
 * la ligne et on en cree une autre. Tous les champs sont facultatifs.
 *
 * Meme logique que StoreComposantRequest, selon le type de la ligne. Une ligne
 * qui n'appartient pas a l'article de l'URL repond 404.
 */
class UpdateComposantRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var ArticlePrix $article */
        $article = $this->route('articlePrix');
        /** @var ArticleComposant $composant */
        $composant = $this->route('composant');

        abort_unless($composant->article_id === $article->id, 404);
        $article->verifierModifiablePar($this->user());

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ArticleComposant $composant */
        $composant = $this->route('composant');
        $mainOeuvre = $composant->type->value === 'main_oeuvre';
        $max = 'max:' . StoreComposantRequest::MAX_QUANTITE;

        return [
            'qualification' => $mainOeuvre
                ? ['sometimes', 'required', 'string', 'max:30', StoreComposantRequest::regleQualification()]
                : ['prohibited'],
            'designation' => ['sometimes', 'required', 'string', 'max:255'],
            'quantite' => ['sometimes', 'required', 'numeric', 'min:0', $max],
            'unite' => $mainOeuvre
                ? ['prohibited']
                : ['sometimes', 'required', 'string', 'max:20'],
            'prix_achat_ht' => $mainOeuvre
                ? ['prohibited']
                : ['sometimes', 'nullable', 'numeric', 'min:0', $max],
            'prix_vente_ht' => $mainOeuvre
                ? ['prohibited']
                : ['sometimes', 'required', 'numeric', 'min:0', $max],
            'ordre' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
