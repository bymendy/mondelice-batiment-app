<?php

namespace App\Models;

use App\Enums\CategorieArticle;
use App\Enums\UniteArticle;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article de la Bibliotheque de prix (tache 3.3).
 *
 * Catalogue reutilisable d'articles/ouvrages references par les lignes de
 * devis (jamais duplique -- seule la ligne de devis signee fige le prix
 * au moment de la signature, regle anti-duplication actee dans le modele
 * de donnees du document de reference, section 6).
 *
 * tenant_id est gere automatiquement par BelongsToTenant : jamais dans
 * $fillable, jamais a fournir explicitement a la creation.
 *
 * @property string $reference
 * @property string $designation
 * @property string|null $description
 * @property CategorieArticle $categorie
 * @property string|null $famille
 * @property string|null $sous_famille
 * @property UniteArticle $unite
 * @property float|null $prix_achat_ht
 * @property float $prix_vente_ht
 * @property float $taux_tva
 * @property string|null $fournisseur
 * @property bool $actif
 */
class ArticlePrix extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'articles_prix';

    protected $fillable = [
        'reference',
        'designation',
        'description',
        'categorie',
        'famille',
        'sous_famille',
        'unite',
        'prix_achat_ht',
        'prix_vente_ht',
        'taux_tva',
        'fournisseur',
        'actif',
    ];

    protected $casts = [
        'categorie' => CategorieArticle::class,
        'unite' => UniteArticle::class,
        'prix_achat_ht' => 'decimal:2',
        'prix_vente_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'actif' => 'boolean',
    ];

    /**
     * Sous-detail de l'article (« pour 1 unite »), dans l'ordre d'affichage.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\ArticleComposant>
     */
    public function composants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ArticleComposant::class, 'article_id')->orderBy('ordre');
    }

    /**
     * Taux de marge calcule a la volee depuis prix_achat_ht et
     * prix_vente_ht -- jamais stocke en base (decision du 25/10/2026,
     * pour eviter toute desynchronisation entre les trois valeurs).
     *
     * @return float|null Pourcentage de marge, ou null si prix_achat_ht
     *                     n'est pas renseigne (impossible de calculer).
     */
    public function tauxMarge(): ?float
    {
        if ($this->prix_achat_ht === null || (float) $this->prix_achat_ht == 0.0) {
            return null;
        }

        return round(
            (((float) $this->prix_vente_ht - (float) $this->prix_achat_ht) / (float) $this->prix_achat_ht) * 100,
            2
        );
    }

    /**
     * Prix de vente TTC calcule a la volee (prix_vente_ht + taux_tva),
     * affiche cote client -- jamais stocke, derive de prix_vente_ht et
     * taux_tva pour la meme raison que tauxMarge().
     *
     * @return float
     */
    public function prixVenteTtc(): float
    {
        return round((float) $this->prix_vente_ht * (1 + ((float) $this->taux_tva / 100)), 2);
    }
}
