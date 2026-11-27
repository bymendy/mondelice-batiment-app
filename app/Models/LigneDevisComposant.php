<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composant fige d'une ligne de devis (sous-detail copie depuis la bibliotheque).
 *
 * Les prix d'achat et le cout de revient horaire sont enregistres mais
 * jamais renvoyes par l'API tant que la permission devis.voir_marge n'existe
 * pas (lot D4).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $ligne_devis_id
 * @property string $type
 * @property string $designation
 * @property string $quantite
 * @property string $unite
 * @property string|null $prix_achat_ht
 * @property string|null $prix_vente_ht
 * @property string|null $taux_horaire_libelle
 * @property string|null $taux_horaire_vente
 * @property string|null $cout_revient_horaire
 * @property int $ordre
 */
class LigneDevisComposant extends Model
{
    use BelongsToTenant;

    /**
     * @var string
     */
    protected $table = 'lignes_devis_composants';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'ligne_devis_id',
        'type',
        'designation',
        'quantite',
        'quantite_base',
        'qualification',
        'fourniture_id',
        'fourniture_standard_id',
        'fourniture_eco_id',
        'fourniture_haut_id',
        'prix_vente_calcule',
        'unite',
        'prix_achat_ht',
        'prix_vente_ht',
        'taux_horaire_libelle',
        'taux_horaire_vente',
        'cout_revient_horaire',
        'ordre',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = ['prix_achat_ht', 'cout_revient_horaire'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantite' => 'decimal:4',
        'quantite_base' => 'decimal:4',
        'prix_vente_calcule' => 'boolean',
        'prix_achat_ht' => 'decimal:4',
        'prix_vente_ht' => 'decimal:4',
        'taux_horaire_vente' => 'decimal:2',
        'cout_revient_horaire' => 'decimal:2',
        'ordre' => 'integer',
    ];

    /**
     * @return BelongsTo<LigneDevis, $this>
     */
    public function ligneDevis(): BelongsTo
    {
        return $this->belongsTo(LigneDevis::class, 'ligne_devis_id');
    }
}
