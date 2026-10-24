<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une situation de travaux : facturation par avancement sur un chantier.
 */
class WorkProgressStatement extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'construction_site_id',
        'statement_number',
        'progress_percentage',
        'amount_ht',
        'statement_date',
        'status',
        'notes',
    ];

    /**
     * @return BelongsTo<ConstructionSite, WorkProgressStatement>
     */
    public function constructionSite(): BelongsTo
    {
        return $this->belongsTo(ConstructionSite::class);
    }
}
