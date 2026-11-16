<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un lot (Demolition, Platrerie...) ou un emplacement (cuisine, salle de
 * bains...) d'un devis. Sert a regrouper les lignes et a donner des
 * sous-totaux.
 *
 * @property int $id
 * @property int $devis_id
 * @property string $type lot | emplacement
 * @property string $libelle
 * @property int $ordre
 */
class LotDevis extends Model
{
    use BelongsToTenant;

    public const TYPE_LOT = 'lot';
    public const TYPE_EMPLACEMENT = 'emplacement';

    protected $table = 'lots_devis';

    protected $fillable = [
        'devis_id',
        'type',
        'libelle',
        'ordre',
    ];

    protected $casts = [
        'ordre' => 'integer',
    ];

    /**
     * @return BelongsTo<Devis, LotDevis>
     */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    /**
     * Les lignes rangees dans ce lot ou cet emplacement.
     *
     * @return HasMany<LigneDevis>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDevis::class, $this->type === self::TYPE_EMPLACEMENT ? 'emplacement_id' : 'lot_id');
    }
}
