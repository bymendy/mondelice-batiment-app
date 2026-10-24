<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rend un modèle Eloquent « multi-tenant » en mode single-database.
 *
 * Deux comportements automatiques dès qu'un modèle utilise ce trait :
 *  1. Lecture : toute requête (all(), find(), where(), etc.) est filtrée
 *     pour ne renvoyer que les lignes du tenant courant (via un global scope).
 *  2. Écriture : à la création d'un enregistrement, la colonne tenant_id
 *     est remplie automatiquement avec le tenant courant si elle est vide.
 *
 * Le modèle doit avoir une colonne `tenant_id` dans sa table.
 *
 * Usage :
 * ```php
 * class Chantier extends Model
 * {
 *     use BelongsToTenant;
 * }
 * ```
 */
trait BelongsToTenant
{
    /**
     * Hook de boot appelé automatiquement par Eloquent au chargement du
     * trait (convention Laravel : boot + NomDuTrait).
     *
     * @return void
     */
    public static function bootBelongsToTenant(): void
    {
        // Filtre automatiquement toutes les lectures sur le tenant courant.
        static::addGlobalScope(function (Builder $builder): void {
            if ($tenantId = tenant_id()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);
            }
        });

        // Remplit automatiquement tenant_id à la création, si absent.
        static::creating(function (Model $model): void {
            if (empty($model->tenant_id) && $tenantId = tenant_id()) {
                $model->tenant_id = $tenantId;
            }
        });
    }
}
