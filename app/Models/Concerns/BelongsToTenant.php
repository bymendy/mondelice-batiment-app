<?php

namespace App\Models\Concerns;

use App\Exceptions\TenantNonIdentifieException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rend un modele Eloquent « multi-tenant » en mode single-database.
 *
 * Comportements automatiques des qu'un modele utilise ce trait :
 *  1. Lecture : toute requete est filtree pour ne renvoyer que les lignes du
 *     tenant courant (global scope nomme « tenant »).
 *  2. Ecriture : a la creation, tenant_id est rempli avec le tenant courant
 *     s'il est vide.
 *  3. Refus par defaut (durcissement du 6 novembre 2026) : sans tenant
 *     identifie, une lecture ou une creation sans tenant_id explicite leve
 *     TenantNonIdentifieException, au lieu de passer sans filtre. Les modeles
 *     qui doivent etre lus avant l'identification (User, Activity) redefinissent
 *     filtreTenantStrict() pour renvoyer false.
 *
 * Le modele doit avoir une colonne `tenant_id` dans sa table.
 *
 * Usage :
 * ```php
 * class Chantier extends Model
 * {
 *     use BelongsToTenant;
 * }
 *
 * // Lecture volontaire de TOUTES les entreprises (commandes d'administration) :
 * Chantier::sansFiltreTenant()->count();
 * ```
 */
trait BelongsToTenant
{
    /**
     * Hook de boot appele automatiquement par Eloquent au chargement du
     * trait (convention Laravel : boot + NomDuTrait).
     *
     * @return void
     *
     * @throws TenantNonIdentifieException Lecture ou creation sans tenant
     *         identifie, sur un modele en mode strict.
     */
    public static function bootBelongsToTenant(): void
    {
        // Filtre toutes les lectures sur le tenant courant (scope nomme pour
        // pouvoir etre retire explicitement par sansFiltreTenant()).
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = tenant_id();

            if ($tenantId) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);

                return;
            }

            $classe = $builder->getModel()::class;

            if ($classe::filtreTenantStrict()) {
                throw new TenantNonIdentifieException(
                    "Lecture de {$classe} sans entreprise identifiee. "
                    . 'Verifiez le middleware, ou utilisez sansFiltreTenant() pour une lecture volontaire.'
                );
            }
        });

        // Remplit tenant_id a la creation, ou refuse si c'est impossible.
        static::creating(function (Model $model): void {
            if (! empty($model->tenant_id)) {
                return;
            }

            if ($tenantId = tenant_id()) {
                $model->tenant_id = $tenantId;

                return;
            }

            if ($model::filtreTenantStrict()) {
                $classe = $model::class;

                throw new TenantNonIdentifieException(
                    "Creation de {$classe} sans entreprise identifiee et sans tenant_id explicite."
                );
            }
        });
    }

    /**
     * Ce modele refuse-t-il tout acces sans entreprise identifiee ?
     *
     * true par defaut. A redefinir a false uniquement pour les modeles qui
     * doivent etre lus avant l'identification de l'entreprise (User pour
     * l'authentification, Activity pour le journal de connexion).
     *
     * @return bool
     */
    public static function filtreTenantStrict(): bool
    {
        return true;
    }

    /**
     * Requete SANS le filtre d'entreprise : lit les donnees de TOUTES les
     * entreprises. Reserve aux commandes d'administration ; ne jamais
     * l'utiliser dans une route ou un controleur.
     *
     * @return Builder
     */
    public static function sansFiltreTenant(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }
}
