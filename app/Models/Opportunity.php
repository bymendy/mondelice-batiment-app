<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une opportunité commerciale (lead), rattachée à un client.
 *
 * Précède le devis dans le cheminement commercial (voir statuts en
 * commentaire de la migration). Une fois le devis créé, l'opportunité
 * reste tracée mais le pilotage se poursuit via l'entité Devis.
 */
class Opportunity extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'status',
        'source',
        'estimated_amount',
        'visit_planned_at',
        'notes',
    ];

    /**
     * Le client à l'origine de cette opportunité.
     *
     * @return BelongsTo<Client, Opportunity>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
