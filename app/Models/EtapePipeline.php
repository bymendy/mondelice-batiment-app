<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Etape du pipeline commercial. Les dix etapes sont des etapes systeme
 * (identifiees par leur code, non supprimables) ; l'entreprise peut changer
 * leur nom et, pour les etapes ouvertes, leur probabilite par defaut.
 *
 * Types : ouverte, gagnee (100 %), perdue (0 %), sans_suite (0 %).
 *
 * @property int $id
 * @property string $code
 * @property string $nom
 * @property bool $systeme
 * @property int $ordre
 * @property int $probabilite_defaut
 * @property string $type
 */
class EtapePipeline extends Model
{
    use BelongsToTenant;

    public const TYPE_OUVERTE = 'ouverte';
    public const TYPE_GAGNEE = 'gagnee';
    public const TYPE_PERDUE = 'perdue';
    public const TYPE_SANS_SUITE = 'sans_suite';

    protected $table = 'etapes_pipeline';

    protected $fillable = [
        'nom',
        'probabilite_defaut',
    ];

    protected $casts = [
        'systeme' => 'boolean',
        'ordre' => 'integer',
        'probabilite_defaut' => 'integer',
    ];

    /**
     * Probabilite imposee par le type d'etape : 100 pour Gagne, 0 pour
     * Perdu et Sans suite, null pour une etape ouverte (libre).
     *
     * @return int|null
     */
    public function probabiliteFixe(): ?int
    {
        return match ($this->type) {
            self::TYPE_GAGNEE => 100,
            self::TYPE_PERDUE, self::TYPE_SANS_SUITE => 0,
            default => null,
        };
    }
}
