<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Un client (personne physique ou entreprise) rattaché à un tenant.
 *
 * Module CRM — point d'entrée du cheminement commercial : un client peut
 * avoir plusieurs opportunités (leads) et plusieurs devis.
 */
class Client extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'email',
        'phone',
        'address',
        'siret',
        'notes',
    ];
}
