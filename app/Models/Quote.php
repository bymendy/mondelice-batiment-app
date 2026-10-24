<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un devis, rattaché à un client et, le cas échéant, à une opportunité.
 *
 * Les montants totaux (total_ht, total_vat, total_ttc) sont calculés à
 * partir des lignes (QuoteLine) et stockés ici pour éviter un recalcul
 * à chaque affichage — à régénérer explicitement à chaque modification
 * d'une ligne (logique à implémenter en Phase 3 avec le moteur event/trigger).
 */
class Quote extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'opportunity_id',
        'quote_number',
        'status',
        'issued_at',
        'valid_until',
        'total_ht',
        'total_vat',
        'total_ttc',
        'signed_at',
        'notes',
    ];

    /**
     * @return BelongsTo<Client, Quote>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Opportunity, Quote>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return HasMany<QuoteLine>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }
}
