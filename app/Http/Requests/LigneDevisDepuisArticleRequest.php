<?php

namespace App\Http\Requests;

/**
 * Ajout d'une ligne de devis depuis un article de la bibliotheque.
 *
 * Reprend les regles de quantite, de lot et d'emplacement de la ligne libre.
 */
class LigneDevisDepuisArticleRequest extends LigneDevisRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $regles = parent::rules();

        return [
            'article_prix_id' => ['required', 'integer'],
            'quantite' => $regles['quantite'],
            'lot_id' => $regles['lot_id'],
            'emplacement_id' => $regles['emplacement_id'],
            // Lot F4b : niveau de prestation de cette ligne ; absent : celui du devis.
            'niveau_prestation' => ['sometimes', 'nullable', 'string', \Illuminate\Validation\Rule::in(\App\Services\Bibliotheque\NiveauPrestation::NIVEAUX)],
        ];
    }
}
