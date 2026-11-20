<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dérogation à un seuil du devis (lot D4c).
 *
 * Le Commercial la demande avec un motif. La Direction l'accepte, en fixant la
 * valeur accordée, ou la refuse avec un motif. Acceptée, elle déplace la limite
 * de ce devis : marge autorisée jusqu'à la valeur accordée, ou remises
 * autorisées jusqu'à cette valeur. Sous cette limite, l'écriture est de
 * nouveau refusée et il faut une nouvelle demande.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $devis_id
 * @property string $type_seuil `marge_globale_min` ou `remise_max`.
 * @property string $statut `en_attente`, `acceptee`, `refusee` ou `annulee`.
 * @property string $seuil Seuil de l'entreprise au moment de la demande.
 * @property string $valeur_demandee
 * @property string|null $valeur_accordee
 * @property string $motif
 * @property string|null $motif_decision
 * @property int|null $demandeur_id
 * @property int|null $decideur_id
 */
class DerogationDevis extends Model
{
    use BelongsToTenant;

    public const EN_ATTENTE = 'en_attente';

    public const ACCEPTEE = 'acceptee';

    public const REFUSEE = 'refusee';

    public const ANNULEE = 'annulee';

    protected $table = 'derogations_devis';

    protected $fillable = [
        'devis_id',
        'type_seuil',
        'statut',
        'seuil',
        'valeur_demandee',
        'valeur_accordee',
        'motif',
        'motif_decision',
        'demandeur_id',
        'decideur_id',
        'demandee_le',
        'decidee_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seuil' => 'decimal:2',
            'valeur_demandee' => 'decimal:2',
            'valeur_accordee' => 'decimal:2',
            'demandee_le' => 'datetime',
            'decidee_le' => 'datetime',
        ];
    }

    /**
     * Valeurs accordées en vigueur sur un devis : pour chaque seuil, la
     * dérogation acceptée la plus récente.
     *
     * @param Devis $devis
     * @return array<string, float> Indexé par type de seuil.
     */
    public static function accordeesPour(Devis $devis): array
    {
        $valeurs = [];
        $acceptees = static::query()
            ->where('devis_id', $devis->getKey())
            ->where('statut', self::ACCEPTEE)
            ->orderByDesc('decidee_le')
            ->orderByDesc('id')
            ->get(['type_seuil', 'valeur_accordee']);

        foreach ($acceptees as $derogation) {
            $valeurs[$derogation->type_seuil] ??= (float) $derogation->valeur_accordee;
        }

        return $valeurs;
    }

    /**
     * @return BelongsTo<Devis, $this>
     */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decideur_id');
    }
}
