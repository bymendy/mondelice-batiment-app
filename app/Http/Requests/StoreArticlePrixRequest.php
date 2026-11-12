<?php

namespace App\Http\Requests;

use App\Enums\TypeArticle;
use App\Enums\UniteArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation de creation d'un article de la Bibliotheque de prix (taches 3.3
 * et lot 3).
 *
 * L'autorisation (qui a le droit de creer un article) passe par les roles
 * et permissions Spatie -- voir ArticlePrixController et le middleware de
 * permission sur la route.
 *
 * Lot 4 : `reference` est facultative (code automatique) et `portee`
 * (`prive` ou `entreprise`) peut etre demandee ; voir ArticlePrixController.
 *
 * Le prix de vente n'est plus saisi : il est calcule depuis le sous-detail.
 * Seul un prix force (facultatif) peut etre impose.
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
                'nullable', 'string', 'max:50',
                Rule::unique('articles_prix', 'reference')->where('tenant_id', tenant_id()),
            ],
            'portee' => ['nullable', Rule::in(['prive', 'entreprise'])],
            'designation' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000', self::regleDescription()],
            'type_article' => ['required', new Enum(TypeArticle::class)],
            'categorie_id' => ['nullable', 'integer', self::regleCategorie()],
            'unite' => ['required', new Enum(UniteArticle::class)],
            'prix_force_ht' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'taux_tva' => ['required', 'numeric', 'min:0', 'max:100'],
            'fournisseur' => ['nullable', 'string', 'max:255'],
            'dtu_reference' => ['nullable', 'string', 'max:120'],
            'dtu_lien' => ['nullable', 'url:http,https', 'max:500'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }

    /** Longueur maximale du descriptif, mise en forme non comptee. */
    public const MAX_DESCRIPTION = 2400;

    /**
     * Le descriptif ne depasse pas 2 400 caracteres de texte : les balises de mise
     * en forme (gras, listes...) ne comptent pas, comme le compteur de l'editeur.
     *
     * @return \Closure(string, mixed, \Closure): void
     */
    public static function regleDescription(): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $echec): void {
            if (! is_string($valeur)) {
                return;
            }

            $texte = trim(html_entity_decode(strip_tags($valeur), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (mb_strlen($texte) > self::MAX_DESCRIPTION) {
                $echec('Le descriptif ne doit pas dépasser 2 400 caractères (mise en forme non comptée).');
            }
        };
    }

    /**
     * La categorie doit exister et etre visible : categorie de reference ou
     * categorie de l'entreprise courante, jamais celle d'une autre entreprise.
     *
     * @return \Illuminate\Validation\Rules\Exists
     */
    public static function regleCategorie(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('categories_prix', 'id')->where(function ($requete): void {
            $requete->whereNull('deleted_at')->where(function ($visible): void {
                $visible->whereNull('tenant_id')->orWhere('tenant_id', tenant_id());
            });
        });
    }
}
