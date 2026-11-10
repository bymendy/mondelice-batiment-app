<?php

namespace App\Models;

use App\Enums\TypeArticle;
use App\Enums\UniteArticle;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Bibliotheque\CalculPrixArticle;
use App\Services\Bibliotheque\ResultatPrixArticle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article de la Bibliotheque de prix (taches 3.3, lots 2 et 3).
 *
 * Catalogue reutilisable d'articles/ouvrages references par les lignes de
 * devis (jamais duplique -- seule la ligne de devis acceptee fige le prix
 * au moment de l'acceptation, regle anti-duplication actee dans le modele
 * de donnees du document de reference, section 6).
 *
 * Le prix de vente n'est PAS stocke : il se calcule depuis le sous-detail
 * (composants) et les taux horaires de l'entreprise, voir calculerPrix().
 * `prix_force_ht`, s'il est renseigne, prime sur le calcul.
 *
 * Articles de reference (lot 3) : tenant_id NULL, partages par toutes les
 * entreprises, en lecture seule pour elles. Ils se creent uniquement par
 * ArticlePrix::creerReference() (seeders, console de l'Editeur).
 *
 * tenant_id est gere automatiquement par BelongsToTenant : jamais dans
 * $fillable, jamais a fournir explicitement a la creation.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $reference
 * @property string $designation
 * @property string|null $description
 * @property TypeArticle $type_article
 * @property int|null $categorie_id
 * @property UniteArticle $unite
 * @property float|null $prix_force_ht
 * @property float $taux_tva
 * @property string|null $fournisseur
 * @property bool $actif
 * @property string|null $portee `prive` ou `entreprise` ; NULL pour une reference
 * @property int|null $proprietaire_id
 * @property int|null $article_origine_id
 * @property int|null $article_source_id
 * @property string|null $source
 * @property int $version
 * @property \Illuminate\Support\Carbon|null $publie_le
 * @property \Illuminate\Support\Carbon|null $prix_mis_a_jour_le
 * @property string|null $dtu_reference Numero de DTU saisi librement (ex. « NF DTU 31.2 P1-1 »)
 * @property string|null $dtu_lien Lien facultatif colle par l'entreprise
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
        'type_article',
        'categorie_id',
        'unite',
        'taux_tva',
        'fournisseur',
        'actif',
        'prix_force_ht',
        'dtu_reference',
        'dtu_lien',
    ];

    protected $casts = [
        'type_article' => TypeArticle::class,
        'unite' => UniteArticle::class,
        'prix_force_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'actif' => 'boolean',
        'version' => 'integer',
        'publie_le' => 'datetime',
        'prix_mis_a_jour_le' => 'datetime',
    ];

    /**
     * Les articles de reference (tenant_id NULL) sont visibles par toutes
     * les entreprises, en lecture seule.
     *
     * @return bool
     */
    public static function inclutReferences(): bool
    {
        return true;
    }

    /**
     * Portee par defaut et date du prix.
     *
     * S'execute apres le remplissage automatique de tenant_id par le trait.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (self $article): void {
            if ($article->tenant_id === null) {
                // Une reference n'a pas de portee (partagee par toutes les entreprises).
                $article->portee = null;

                return;
            }

            if ($article->portee === null) {
                $article->portee = 'entreprise';
            }
        });

        static::saving(function (self $article): void {
            if ($article->isDirty('prix_force_ht')) {
                $article->prix_mis_a_jour_le = now();
            }
        });
    }

    /**
     * Sous-detail de l'article (« pour 1 unite »), dans l'ordre d'affichage.
     *
     * @return HasMany<ArticleComposant>
     */
    public function composants(): HasMany
    {
        return $this->hasMany(ArticleComposant::class, 'article_id')->orderBy('ordre');
    }

    /**
     * Categorie de l'arborescence (Domaine > Corps d'etat > Famille d'ouvrages).
     *
     * @return BelongsTo<CategoriePrix, ArticlePrix>
     */
    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategoriePrix::class, 'categorie_id');
    }

    /**
     * Article de reference dont cet article est une version personnalisee.
     *
     * @return BelongsTo<ArticlePrix, ArticlePrix>
     */
    public function origine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'article_origine_id');
    }

    /**
     * Calcule le prix de l'article (jamais stocke).
     *
     * @param array<string, TauxHoraire> $tauxParCode Taux de l'entreprise courante
     *        (voir TauxHoraire::indexesParCode()).
     * @return ResultatPrixArticle
     *
     * @throws \App\Exceptions\QualificationInconnueException Une ligne de
     *         main-d'oeuvre cite une qualification absente des taux.
     */
    public function calculerPrix(array $tauxParCode): ResultatPrixArticle
    {
        return (new CalculPrixArticle())->calculer(
            $this->composants,
            $tauxParCode,
            $this->prix_force_ht !== null ? (float) $this->prix_force_ht : null
        );
    }
}
