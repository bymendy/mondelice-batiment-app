<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de devis : libelle, quantite, prix unitaire et TVA, copies
 * depuis la bibliotheque de prix a la creation. Elles sont figees : le prix
 * d'un devis ne change pas si la bibliotheque evolue.
 *
 * `prix_revient_unitaire` garde le revient de l'article au moment de la copie
 * (visible selon le role, pour le panneau de marge).
 *
 * @property int $id
 * @property int $devis_id
 * @property int|null $lot_id
 * @property int|null $emplacement_id
 * @property int|null $article_prix_id
 * @property string $libelle
 * @property string|null $descriptif
 * @property string|null $dtu_reference
 * @property string $quantite
 * @property string|null $expression_metre
 * @property string $unite
 * @property string $prix_unitaire_ht
 * @property string|null $prix_revient_unitaire
 * @property string $taux_tva
 * @property int $ordre
 */
class LigneDevis extends Model
{
    use BelongsToTenant;

    protected $table = 'lignes_devis';

    protected $fillable = [
        'devis_id',
        'lot_id',
        'emplacement_id',
        'article_prix_id',
        'libelle',
        'descriptif',
        'dtu_reference',
        'quantite',
        'expression_metre',
        'unite',
        'prix_unitaire_ht',
        'prix_revient_unitaire',
        'taux_tva',
        'ordre',
    ];

    protected $casts = [
        'quantite' => 'decimal:4',
        'prix_unitaire_ht' => 'decimal:2',
        'prix_revient_unitaire' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'ordre' => 'integer',
    ];

    /**
     * @return BelongsTo<Devis, LigneDevis>
     */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    /**
     * @return BelongsTo<LotDevis, LigneDevis>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotDevis::class, 'lot_id');
    }

    /**
     * @return BelongsTo<LotDevis, LigneDevis>
     */
    public function emplacement(): BelongsTo
    {
        return $this->belongsTo(LotDevis::class, 'emplacement_id');
    }

    /**
     * L'article de la bibliotheque dont la ligne est issue (meme archive).
     *
     * @return BelongsTo<ArticlePrix, LigneDevis>
     */
    public function articlePrix(): BelongsTo
    {
        return $this->belongsTo(ArticlePrix::class)->withTrashed();
    }
}
