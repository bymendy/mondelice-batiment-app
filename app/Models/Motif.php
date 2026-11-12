<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Motif (de perte d'une affaire, ou d'ecart d'une demande entrante) : liste
 * parametrable par entreprise.
 *
 * @property int $id
 * @property string $type perte | ecart_demande
 * @property string $libelle
 * @property bool $actif
 * @property int $ordre
 */
class Motif extends Model
{
    use BelongsToTenant;

    public const TYPE_PERTE = 'perte';
    public const TYPE_ECART_DEMANDE = 'ecart_demande';

    protected $table = 'motifs';

    protected $fillable = [
        'type',
        'libelle',
        'actif',
        'ordre',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];
}
