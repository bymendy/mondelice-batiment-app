<?php

namespace App\Models;

use App\Enums\TypeComposant;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ligne du sous-detail d'un article, « pour 1 unite » (Bibliotheque de prix, lot 2).
 *
 * Main-d'oeuvre : quantite en heures decimales (1,15 h = 1 h 09 min), unite `h`,
 * qualification citant un taux horaire, aucun prix (lu dans taux_horaires).
 * Autres types : prix de vente obligatoire, prix d'achat facultatif.
 *
 * Garde-fou : une ligne ne peut appartenir qu'a un article de la MEME
 * entreprise, meme si l'identifiant d'un article d'une autre entreprise est
 * fourni (controle a l'enregistrement, en plus de la cle etrangere).
 *
 * tenant_id est gere automatiquement par BelongsToTenant.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $article_id
 * @property TypeComposant $type
 * @property string|null $qualification
 * @property int|null $fourniture_id
 * @property string $designation
 * @property float $quantite
 * @property string $unite
 * @property float|null $prix_achat_ht
 * @property float|null $prix_vente_ht
 * @property int $ordre
 */
class ArticleComposant extends Model
{
    use BelongsToTenant;

    protected $table = 'article_composants';

    protected $fillable = [
        'article_id',
        'type',
        'qualification',
        'fourniture_id',
        'designation',
        'quantite',
        'unite',
        'prix_achat_ht',
        'prix_vente_ht',
        'ordre',
    ];

    protected $casts = [
        'type' => TypeComposant::class,
        'quantite' => 'decimal:4',
        'prix_achat_ht' => 'decimal:4',
        'prix_vente_ht' => 'decimal:4',
        'ordre' => 'integer',
    ];

    /**
     * Branche le controle « meme entreprise que l'article ».
     *
     * S'execute apres le remplissage automatique de tenant_id par le trait.
     *
     * @return void
     */
    protected static function booted(): void
    {
        $controler = function (self $composant): void {
            // Lecture directe : ni filtre d'entreprise ni conversion de l'article.
            $article = DB::table('articles_prix')->where('id', $composant->article_id)->first(['tenant_id']);

            if ($article === null) {
                throw new LogicException("Article introuvable : {$composant->article_id}");
            }

            if ($article->tenant_id !== $composant->tenant_id) {
                throw new LogicException(
                    "Le composant ne peut pas etre rattache a l'article {$composant->article_id} : autre entreprise."
                );
            }
        };

        static::creating($controler);
        static::updating($controler);
    }

    /**
     * Article auquel appartient la ligne.
     *
     * @return BelongsTo<ArticlePrix, ArticleComposant>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(ArticlePrix::class, 'article_id');
    }
}
