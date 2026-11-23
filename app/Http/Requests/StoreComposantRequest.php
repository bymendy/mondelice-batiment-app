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
 * Le type `fourniture_base` (lot F1) cite une fourniture de la Base de
 * fournitures : sa designation, son unite et ses prix viennent de la fourniture.
 *
 * L'autorisation (qui peut modifier cet article) est controlee avant la
 * validation : 403 plutot que 422.
 */
class StoreComposantRequest extends FormRequest
{
    public const TYPES = ['main_oeuvre', 'fourniture_base', 'fourniture_libre', 'materiel', 'frais'];

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
        return self::reglesLigne($this->all());
    }

    /**
     * Regles d'une ligne du sous-detail selon son type. Le prix de vente d'une
     * fourniture est facultatif ici : s'il manque, le controleur le calcule depuis
     * le prix d'achat (PrixVenteFourniture) ou refuse la ligne.
     *
     * @param array<string, mixed> $donnees Champs de la ligne.
     * @return array<string, mixed>
     */
    public static function reglesLigne(array $donnees): array
    {
        $mainOeuvre = ($donnees['type'] ?? null) === 'main_oeuvre';
        // Fourniture de la base : seuls la fourniture et la quantite comptent, le reste est lu dans la fourniture.
        $base = ($donnees['type'] ?? null) === 'fourniture_base';

        return [
            'type' => ['required', Rule::in(self::TYPES)],
            'fourniture_id' => $base ? ['required', 'integer', self::regleFourniture()] : ['prohibited'],
            'qualification' => $mainOeuvre
                ? ['required', 'string', 'max:30', self::regleQualification()]
                : ['prohibited'],
            'designation' => $mainOeuvre
                ? ['nullable', 'string', 'max:255']
                : ($base ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'max:255']),
            'quantite' => ['required', 'numeric', 'min:0', 'max:' . self::MAX_QUANTITE],
            'unite' => $mainOeuvre
                ? ['nullable', Rule::in(['h'])]
                : ($base ? ['nullable', 'string', 'max:20'] : ['required', 'string', 'max:20']),
            'prix_achat_ht' => $mainOeuvre
                ? ['prohibited']
                : ['nullable', 'numeric', 'min:0', 'max:' . self::MAX_QUANTITE],
            'prix_vente_ht' => $mainOeuvre
                ? ['prohibited']
                : ['nullable', 'numeric', 'min:0', 'max:' . self::MAX_QUANTITE],
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

    /**
     * La fourniture citee doit exister dans la Base de fournitures de
     * l'entreprise, et etre active (une fourniture archivee ne s'ajoute plus).
     *
     * @return Closure
     */
    public static function regleFourniture(): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec): void {
            if (! is_numeric($valeur) || ! \App\Models\Fourniture::query()->whereKey((int) $valeur)->where('actif', true)->exists()) {
                $echec('Cette fourniture n\'existe pas (ou plus) dans la Base de fournitures de l\'entreprise.');
            }
        };
    }
}
