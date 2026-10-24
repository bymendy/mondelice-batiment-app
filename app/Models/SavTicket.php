<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ticket SAV, rattaché à un chantier livré.
 *
 * Entité propre et complète (pas un simple statut) : garde son propre
 * cycle de vie (ouvert -> planifié -> intervention faite -> clos).
 */
class SavTicket extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'construction_site_id',
        'status',
        'subject',
        'description',
        'reported_at',
        'intervention_planned_at',
        'closed_at',
    ];

    /**
     * @return BelongsTo<ConstructionSite, SavTicket>
     */
    public function constructionSite(): BelongsTo
    {
        return $this->belongsTo(ConstructionSite::class);
    }
}
