<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de l'historique des etapes d'une affaire. Table en INSERTION
 * SEULE : une ligne n'est jamais modifiee ni supprimee (la base le refuse).
 *
 * Meme vocabulaire que le journal d'activite : triggered_by (10 caracteres
 * max, « human » par defaut) et automation_rule (20 caracteres max).
 *
 * @property int $id
 * @property int $opportunite_id
 * @property int|null $etape_depuis_id
 * @property int $etape_vers_id
 * @property \Illuminate\Support\Carbon $change_le
 * @property int|null $auteur_id
 * @property string $triggered_by
 * @property string|null $automation_rule
 * @property int|null $devis_id
 * @property bool $retour_arriere
 */
class OpportuniteEtape extends Model
{
    use BelongsToTenant;

    protected $table = 'opportunite_etapes';

    public $timestamps = false;

    protected $fillable = [
        'opportunite_id',
        'etape_depuis_id',
        'etape_vers_id',
        'change_le',
        'auteur_id',
        'triggered_by',
        'automation_rule',
        'devis_id',
        'retour_arriere',
    ];

    protected $casts = [
        'change_le' => 'datetime',
        'retour_arriere' => 'boolean',
    ];

    /**
     * @return BelongsTo<Opportunite, OpportuniteEtape>
     */
    public function opportunite(): BelongsTo
    {
        return $this->belongsTo(Opportunite::class);
    }

    /**
     * @return BelongsTo<EtapePipeline, OpportuniteEtape>
     */
    public function etapeVers(): BelongsTo
    {
        return $this->belongsTo(EtapePipeline::class, 'etape_vers_id');
    }
}
