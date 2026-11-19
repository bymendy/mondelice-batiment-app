<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerte de seuil sur un devis (lot D4b). Elle regroupe les refus d'une même
 * personne, sur un même devis et un même seuil, pendant le délai réglé par la
 * Direction : le premier refus prévient, les suivants mettent l'alerte à jour.
 *
 * Elle sert à suivre un devis, pas à classer les personnes : aucun total par
 * personne n'est calculé.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $devis_id
 * @property int|null $user_id Auteur des écritures refusées.
 * @property string $type_seuil `marge_globale_min` ou `remise_max`.
 * @property string $moment Dernier moment : `ecriture` ou `envoi`.
 * @property int $nombre Nombre de refus regroupés.
 * @property string $seuil Seuil en vigueur au dernier refus.
 * @property string $derniere_valeur Valeur du dernier refus.
 * @property string $pire_valeur Marge la plus basse, ou remise la plus haute.
 */
class AlerteSeuil extends Model
{
    use BelongsToTenant;

    protected $table = 'alertes_seuils';

    protected $fillable = [
        'devis_id',
        'user_id',
        'type_seuil',
        'moment',
        'nombre',
        'seuil',
        'derniere_valeur',
        'pire_valeur',
        'premiere_le',
        'derniere_le',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre' => 'integer',
            'seuil' => 'decimal:2',
            'derniere_valeur' => 'decimal:2',
            'pire_valeur' => 'decimal:2',
            'premiere_le' => 'datetime',
            'derniere_le' => 'datetime',
        ];
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
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
