<?php

namespace App\Http\Requests;

use App\Models\ArticlePrix;
use App\Models\TauxHoraire;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de l'ajout d'une ligne au sous-detail d'un article (Bibliotheque
 * de prix, lot 5).
 *
 * Les regles reprennent les contraintes de la base :
 *  - main-d'oeuvre : qualification (taux horaire de l'entreprise) obligatoire,
 *    unite `h`, aucun prix (lu dans les taux) ;
 *  - autres types : designation, unite et prix de vente obligatoires, prix
 *    d'achat facultatif.
 *
 * Le type `fourniture_base` n'est pas accepte tant que la Base de fournitures
 * n'existe pas.
 *
 * L'autorisation (qui peut modifier cet article) est controlee avant la
 * validation : 403 plutot que 422.
 */
class StoreComposantRequest extends FormRequest
{
    public const TYPES = ['main_oeuvre', 'fourniture_libre', 'materiel', 'frais'];

    public const MAX_QUANTITE = '99999999.9999';

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
        $mainOeuvre = $this->input('type') === 'main_oeuvre';

        return [
            'type' => ['required', Rule::in(self::TYPES)],
            'qualification' => $mainOeuvre
                ? ['required', 'string', 'max:30', self::regleQualification()]
                : ['prohibited'],
            'designation' => $mainOeuvre
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string', 'max:255'],
            'quantite' => ['required', 'numeric', 'min:0', 'max:' . self::MAX_QUANTITE],
            'unite' => $mainOeuvre
                ? ['nullable', Rule::in(['h'])]
                : ['required', 'string', 'max:20'],
            'prix_achat_ht' => $mainOeuvre
                ? ['prohibited']
                : ['nullable', 'numeric', 'min:0', 'max:' . self::MAX_QUANTITE],
            'prix_vente_ht' => $mainOeuvre
                ? ['prohibited']
                : ['required', 'numeric', 'min:0', 'max:' . self::MAX_QUANTITE],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * La qualification doit etre un taux horaire de l'entreprise courante.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function regleQualification(): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec): void {
            if (! is_string($valeur) || ! array_key_exists($valeur, TauxHoraire::indexesParCode())) {
                $echec('Cette qualification n\'existe pas dans les taux horaires de l\'entreprise.');
            }
        };
    }
}
