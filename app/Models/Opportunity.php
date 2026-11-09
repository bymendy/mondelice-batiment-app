<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une opportunite commerciale (lead), rattachee a un client.
 *
 * Precede le devis dans le cheminement commercial. Une fois le devis cree,
 * l'opportunite reste tracee mais le pilotage se poursuit via l'entite Devis.
 * Table reprise de la Phase 1, a reformer a la tache 3.5 (statuts, noms).
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
     * Le client a l origine de cette opportunite (meme archive).
     *
     * @return BelongsTo<Client, Opportunity>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
