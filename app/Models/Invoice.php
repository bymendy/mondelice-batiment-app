<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une facture (acompte, situation ou finale), rattachée à un chantier.
 *
 * Module volontairement distinct du suivi de chantier (écran séparé,
 * conducteur de travaux ≠ administratif).
 */
class Invoice extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'construction_site_id',
        'work_progress_statement_id',
        'invoice_number',
        'type',
        'status',
        'amount_ht',
        'amount_vat',
        'amount_ttc',
        'issued_at',
        'due_at',
        'paid_at',
        'notes',
    ];

    /**
     * @return BelongsTo<ConstructionSite, Invoice>
     */
    public function constructionSite(): BelongsTo
    {
        return $this->belongsTo(ConstructionSite::class);
    }

    /**
     * @return BelongsTo<WorkProgressStatement, Invoice>
     */
    public function workProgressStatement(): BelongsTo
    {
        return $this->belongsTo(WorkProgressStatement::class);
    }
}
