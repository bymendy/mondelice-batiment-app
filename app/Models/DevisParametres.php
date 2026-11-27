<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paramètres d'un devis : copie des réglages de l'entreprise à la création,
 * ajustable pour ce devis seulement (décisions du 20 novembre 2026).
 *
 * `coefficient_zone` est préparé : il vaut toujours 1,00 tant que les zones
 * ne sont pas activées.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $devis_id
 * @property string $coefficient_difficulte De 1,00 à 1,50 : multiplie le temps de main-d'œuvre.
 * @property string $coefficient_zone Multipliera le taux horaire de vente (1,00 pour l'instant).
 * @property string $tva_defaut
 * @property string $tva_source entreprise | modifie
 * @property string|null $marge_fournitures Copie, sans effet avant la Base de fournitures.
 */
class DevisParametres extends Model
{
    use BelongsToTenant;

    protected $table = 'devis_parametres';

    protected $fillable = [
        'devis_id',
        'coefficient_difficulte',
        'coefficient_zone',
        'tva_defaut',
        'tva_source',
        'marge_fournitures',
        'marge_fournitures_source',
        'frais_generaux_fournitures',
        'niveau_prestation',
    ];

    protected $casts = [
        'coefficient_difficulte' => 'decimal:2',
        'coefficient_zone' => 'decimal:2',
        'tva_defaut' => 'decimal:2',
        'marge_fournitures' => 'decimal:2',
        'frais_generaux_fournitures' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<Devis, $this>
     */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }
}
