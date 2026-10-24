<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une intervention planifiée : un salarié, un créneau, un chantier.
 */
class Intervention extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'construction_site_id',
        'assigned_user_id',
        'status',
        'scheduled_start_at',
        'scheduled_end_at',
        'notes',
    ];

    /**
     * @return BelongsTo<ConstructionSite, Intervention>
     */
    public function constructionSite(): BelongsTo
    {
        return $this->belongsTo(ConstructionSite::class);
    }

    /**
     * Le salarié assigné à cette intervention.
     *
     * @return BelongsTo<User, Intervention>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
