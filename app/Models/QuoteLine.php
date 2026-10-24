<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de devis : désignation, quantité, prix unitaire et TVA, copiés
 * depuis la bibliothèque de prix au moment de la création (figés ensuite).
 */
class QuoteLine extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quote_id',
        'price_library_item_id',
        'designation',
        'unit',
        'quantity',
        'unit_price_ht',
        'vat_rate',
        'position',
    ];

    /**
     * @return BelongsTo<Quote, QuoteLine>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<PriceLibraryItem, QuoteLine>
     */
    public function priceLibraryItem(): BelongsTo
    {
        return $this->belongsTo(PriceLibraryItem::class);
    }
}
