<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pointage d'heures, rattaché à une intervention et à un utilisateur.
 *
 * Donnée clé pour le calcul de la rentabilité réelle d'un chantier.
 */
class TimeEntry extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'intervention_id',
        'user_id',
        'started_at',
        'ended_at',
        'duration_hours',
        'notes',
    ];

    /**
     * @return BelongsTo<Intervention, TimeEntry>
     */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }

    /**
     * @return BelongsTo<User, TimeEntry>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
