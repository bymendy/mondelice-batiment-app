<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de devis : designation, quantite, prix unitaire et TVA, copies
 * depuis la bibliotheque de prix a la creation (figes ensuite : le prix signe
 * ne change pas si le catalogue evolue).
 */
class QuoteLine extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quote_id',
        'article_prix_id',
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
     * L'article de la bibliotheque dont la ligne est issue (meme archive).
     *
     * @return BelongsTo<ArticlePrix, QuoteLine>
     */
    public function articlePrix(): BelongsTo
    {
        return $this->belongsTo(ArticlePrix::class)->withTrashed();
    }
}
