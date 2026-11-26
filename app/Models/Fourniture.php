<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une fourniture réutilisable de la Base de fournitures (lot F1).
 *
 * Commune à toute l'entreprise (pas de version privée). Prix :
 *  - `prix_tarif_ht` et `remise_pct` (sinon la remise par défaut du
 *    fournisseur) donnent le `prix_achat_ht` net ; sans prix tarif, le prix
 *    d'achat se saisit directement ;
 *  - `prix_vente_ht` est saisi (`prix_vente_saisi` vrai) ou calculé depuis le
 *    prix d'achat avec la marge par défaut des fournitures.
 *
 * Lien vivant : les lignes d'articles `fourniture_base` gardent une copie de
 * la désignation, de l'unité et des prix, remise à jour à chaque modification
 * (SynchronisationFournitures). Les devis déjà écrits ne bougent pas.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $fournisseur_id
 * @property string|null $reference Référence du fournisseur ou du fabricant.
 * @property string $designation
 * @property string $unite
 * @property string|null $prix_tarif_ht
 * @property string|null $remise_pct NULL = remise par défaut du fournisseur.
 * @property string|null $prix_achat_ht Prix d'achat net.
 * @property string $prix_vente_ht
 * @property bool $prix_vente_saisi
 * @property bool $actif
 * @property \Illuminate\Support\Carbon|null $date_tarif Date du tarif du fournisseur (information).
 */
class Fourniture extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'fournitures';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fournisseur_id',
        'reference',
        'designation',
        'unite',
        'prix_tarif_ht',
        'remise_pct',
        'prix_achat_ht',
        'prix_vente_ht',
        'prix_vente_saisi',
        'actif',
        'date_tarif',
    ];

    protected $casts = [
        'prix_tarif_ht' => 'decimal:4',
        'remise_pct' => 'decimal:2',
        'prix_achat_ht' => 'decimal:4',
        'prix_vente_ht' => 'decimal:4',
        'prix_vente_saisi' => 'boolean',
        'actif' => 'boolean',
        'date_tarif' => 'date:Y-m-d',
    ];

    /**
     * @return BelongsTo<Fournisseur, $this>
     */
    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }
}
