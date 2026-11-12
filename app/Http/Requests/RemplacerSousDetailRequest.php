<?php

namespace App\Http\Requests;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

/**
 * Validation de l'enregistrement d'un sous-detail complet (Bibliotheque de
 * prix, lot 8) : la liste des lignes de l'article, dans l'ordre voulu.
 *
 * Chaque ligne est decrite en entier, avec les memes regles que l'ajout d'une
 * ligne (StoreComposantRequest::reglesLigne) :
 *  - sans `id`, la ligne est creee ;
 *  - avec `id`, la ligne existante est mise a jour (son type ne change pas) ;
 *  - une ligne existante absente de la liste est supprimee.
 *
 * Le prix de vente d'une fourniture peut etre omis si le prix d'achat est donne
 * (marge par defaut, voir PrixVenteFourniture). L'`ordre` eventuel est ignore :
 * c'est la position dans la liste qui fait foi.
 *
 * Tout ou rien : une seule ligne invalide et rien n'est enregistre.
 */
class RemplacerSousDetailRequest extends FormRequest
{
    public const MAX_LIGNES = 200;

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
            'composants' => ['present', 'array', 'max:' . self::MAX_LIGNES],
        ];
    }

    /**
     * Controle chaque ligne selon son type, puis les identifiants.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validateur): void {
            /** @var ArticlePrix $article */
            $article = $this->route('articlePrix');
            $existants = ArticleComposant::query()->where('article_id', $article->id)->get()->keyBy('id');
            $vus = [];

            foreach ((array) $this->input('composants', []) as $position => $ligne) {
                if (! is_array($ligne)) {
                    $validateur->errors()->add("composants.{$position}", 'Chaque ligne doit être un objet.');

                    continue;
                }

                $sous = ValidatorFacade::make(
                    $ligne,
                    StoreComposantRequest::reglesLigne($ligne) + ['id' => ['nullable', 'integer']]
                );

                foreach ($sous->errors()->messages() as $champ => $messages) {
                    foreach ($messages as $message) {
                        $validateur->errors()->add("composants.{$position}.{$champ}", $message);
                    }
                }

                $id = $ligne['id'] ?? null;

                if ($id === null || ! is_numeric($id)) {
                    continue;
                }

                $id = (int) $id;

                if (! $existants->has($id)) {
                    $validateur->errors()->add("composants.{$position}.id", 'Cette ligne n\'appartient pas à l\'article.');
                } elseif (isset($vus[$id])) {
                    $validateur->errors()->add("composants.{$position}.id", 'Cette ligne est indiquée deux fois.');
                } elseif ($existants->get($id)->type->value !== ($ligne['type'] ?? null)) {
                    $validateur->errors()->add("composants.{$position}.type", 'Le type d\'une ligne ne change pas : supprimez-la et créez-en une autre.');
                }

                $vus[$id] = true;
            }
        }];
    }
}
