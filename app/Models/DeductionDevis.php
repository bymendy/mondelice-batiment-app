<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remise d'un devis : par lot (lot_id renseigne) ou globale (lot_id vide),
 * en pourcentage ou en montant. Son libelle est visible du client.
 *
 * La remise par ligne n'est pas ici : elle est portee par la ligne elle-meme
 * (lignes_devis.remise_pct).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $devis_id
 * @property int|null $lot_id
 * @property string $libelle
 * @property string $type
 * @property string $valeur
 * @property int $ordre
 */
class DeductionDevis extends Model
{
    use BelongsToTenant;

    public const TYPE_POURCENTAGE = 'pourcentage';
    public const TYPE_MONTANT = 'montant';

    /**
     * @var string
     */
    protected $table = 'deductions_devis';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'devis_id',
        'lot_id',
        'libelle',
        'type',
        'valeur',
        'ordre',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'valeur' => 'decimal:2',
        'ordre' => 'integer',
    ];

    /**
     * @return BelongsTo<Devis, $this>
     */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    /**
     * @return BelongsTo<LotDevis, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(LotDevis::class, 'lot_id');
    }
}
