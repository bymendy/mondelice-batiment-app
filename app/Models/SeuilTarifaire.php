<?php

namespace App\Models;

use App\Enums\TypeSeuil;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Seuil plancher fixe par la Direction pour son entreprise (lot 6).
 *
 * Un seuil par type. `valeur` est une marge minimum en % du prix de vente.
 * tenant_id est gere automatiquement par BelongsToTenant.
 *
 * @property int $id
 * @property string $tenant_id
 * @property TypeSeuil $type
 * @property float $valeur
 * @property bool $actif
 * @property int|null $defini_par
 */
class SeuilTarifaire extends Model
{
    use BelongsToTenant;

    protected $table = 'seuils_tarifaires';

    protected $fillable = [
        'type',
        'valeur',
        'actif',
        'defini_par',
    ];

    protected $casts = [
        'type' => TypeSeuil::class,
        'valeur' => 'decimal:2',
        'actif' => 'boolean',
    ];

    /**
     * Seuils actifs de l'entreprise courante, indexes par type.
     *
     * @return array<string, float> `marge_mo_min` => 20.0, ...
     */
    public static function actifsParType(): array
    {
        $seuils = [];

        foreach (static::query()->where('actif', true)->get() as $seuil) {
            $seuils[$seuil->type->value] = (float) $seuil->valeur;
        }

        return $seuils;
    }
}
