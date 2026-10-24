<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Un fournisseur, référencé par les commandes fournisseur (purchase_orders).
 *
 * Fiche réutilisable d'une commande à l'autre, pour éviter de stocker le
 * fournisseur en texte libre à chaque commande.
 */
class Supplier extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'contact_name',
        'email',
        'phone',
        'address',
        'siret',
        'notes',
    ];
}
