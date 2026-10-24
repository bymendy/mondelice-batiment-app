<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Un item de la bibliothèque de prix (catalogue de prestations/fournitures).
 *
 * Référencé par les lignes de devis (quote_lines, à venir) pour éviter la
 * double saisie des prix et taux de TVA.
 */
class PriceLibraryItem extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'designation',
        'unit',
        'unit_price_ht',
        'vat_rate',
        'category',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_ht' => 'decimal:2',
            'vat_rate' => 'decimal:2',
        ];
    }
}
