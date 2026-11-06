<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un devis, rattache a un contact et, le cas echeant, a une opportunite.
 *
 * Les montants totaux (total_ht, total_vat, total_ttc) sont calcules a
 * partir des lignes (QuoteLine) et stockes ici pour eviter un recalcul
 * a chaque affichage. Table reprise de la Phase 1, a reformer a la
 * tache 3.6 (renommage en `devis`, machine a etats).
 */
class Quote extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
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
     * Le contact destinataire du devis (meme archive).
     *
     * @return BelongsTo<Contact, Quote>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
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
