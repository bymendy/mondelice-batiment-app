<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un fournisseur de l'entreprise (ancienne table `suppliers` de la phase 1).
 *
 * Fiche minimale au lot F1 : coordonnées et remise par défaut, appliquée aux
 * fournitures qui n'ont pas leur propre remise. Le module Achats l'enrichira.
 * Les commandes fournisseur (`purchase_orders.supplier_id`) pointent dessus.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $nom
 * @property string|null $reference Code interne du fournisseur.
 * @property string $remise_defaut_pct Remise sur le prix tarif, en %.
 * @property bool $actif
 */
class Fournisseur extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'fournisseurs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nom',
        'reference',
        'contact_nom',
        'email',
        'telephone',
        'adresse',
        'siret',
        'notes',
        'remise_defaut_pct',
        'actif',
    ];

    protected $casts = [
        'remise_defaut_pct' => 'decimal:2',
        'actif' => 'boolean',
    ];

    /**
     * @return HasMany<Fourniture, $this>
     */
    public function fournitures(): HasMany
    {
        return $this->hasMany(Fourniture::class);
    }
}
