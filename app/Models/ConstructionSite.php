<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un chantier, créé à partir d'un devis accepté.
 *
 * Dossier central de l'application (principe directeur : une affaire = un
 * dossier unique relié à tous les modules). Voir les statuts possibles en
 * commentaire de la migration.
 */
class ConstructionSite extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quote_id',
        'reference',
        'status',
        'address',
        'planned_start_at',
        'planned_end_at',
        'actual_start_at',
        'actual_end_at',
        'received_at',
        'settled_at',
        'notes',
    ];

    /**
     * @return BelongsTo<Quote, ConstructionSite>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
