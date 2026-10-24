<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une commande fournisseur, rattachée à un fournisseur et à un chantier.
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'construction_site_id',
        'order_number',
        'status',
        'amount_ht',
        'ordered_at',
        'expected_delivery_at',
        'delivered_at',
        'notes',
    ];

    /**
     * @return BelongsTo<Supplier, PurchaseOrder>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<ConstructionSite, PurchaseOrder>
     */
    public function constructionSite(): BelongsTo
    {
        return $this->belongsTo(ConstructionSite::class);
    }
}
