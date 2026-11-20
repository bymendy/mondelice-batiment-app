<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Taux horaire d'une qualification, copié dans un devis.
 *
 * Seul le taux de vente s'ajuste pour le devis. Le coût de revient est une
 * copie figée, réservée à ceux qui voient la marge.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $devis_id
 * @property string $code
 * @property string $libelle
 * @property string $taux_vente_ht Taux du devis.
 * @property string $taux_vente_entreprise Taux de l'entreprise au moment de la copie.
 * @property string|null $cout_revient_ht
 * @property string $source entreprise | modifie
 * @property int $ordre
 */
class DevisTauxHoraire extends Model
{
    use BelongsToTenant;

    protected $table = 'devis_taux_horaires';

    protected $fillable = [
        'devis_id',
        'code',
        'libelle',
        'taux_vente_ht',
        'taux_vente_entreprise',
        'cout_revient_ht',
        'source',
        'ordre',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['cout_revient_ht'];

    protected $casts = [
        'taux_vente_ht' => 'decimal:2',
        'taux_vente_entreprise' => 'decimal:2',
        'cout_revient_ht' => 'decimal:2',
        'ordre' => 'integer',
    ];
}
