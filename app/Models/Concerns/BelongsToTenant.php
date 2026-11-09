<?php

namespace App\Models\Concerns;

use App\Exceptions\ReferenceNonModifiableException;
use App\Exceptions\TenantNonIdentifieException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

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
 * Lignes « de reference » (ajout du 9 novembre 2026, desactive par defaut) :
 * un modele peut redefinir inclutReferences() pour renvoyer true. Il voit alors,
 * en plus de ses lignes, les lignes partagees dont tenant_id est NULL
 * (ex. categories de la Bibliotheque de prix). Dans ce cas :
 *  - une entreprise ne peut ni modifier ni supprimer une reference
 *    (ReferenceNonModifiableException) ;
 *  - une reference se cree uniquement par creerReference() ;
 *  - une reference se modifie uniquement apres autoriserModificationReference()
 *    (seeders et commandes d'administration).
 * Les modeles qui ne redefinissent pas inclutReferences() gardent le
 * comportement strict d'origine, sans aucune difference.
 *
 * Limite connue : les modifications ou suppressions « en masse » faites par le
 * query builder (Modele::where(...)->update()) ne declenchent pas les
 * evenements du modele et ne sont donc pas controlees. Pour un modele a
 * references, modifier toujours des instances chargees.
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
     * Indicateur d'instance : creation d'une reference en cours. Jamais
     * persiste ; active uniquement par creerReference().
     *
     * @var bool
     */
    protected bool $creationReferenceAutorisee = false;

    /**
     * Indicateur d'instance : modification ou suppression d'une reference
     * autorisee. Jamais persiste ; active par autoriserModificationReference().
     *
     * @var bool
     */
    protected bool $modificationReferenceAutorisee = false;

    /**
     * Hook de boot appele automatiquement par Eloquent au chargement du
     * trait (convention Laravel : boot + NomDuTrait).
     *
     * @return void
     *
     * @throws TenantNonIdentifieException Lecture ou creation sans tenant
     *         identifie, sur un modele en mode strict.
     * @throws ReferenceNonModifiableException Modification ou suppression d'une
     *         reference sans autorisation explicite.
     */
    public static function bootBelongsToTenant(): void
    {
        // Filtre toutes les lectures sur le tenant courant (scope nomme pour
        // pouvoir etre retire explicitement par sansFiltreTenant()).
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = tenant_id();

            if ($tenantId) {
                $modele = $builder->getModel();
                $colonne = $modele->getTable().'.tenant_id';

                if ($modele::inclutReferences()) {
                    // Lignes du tenant courant OU lignes de reference (tenant_id NULL).
                    // Le groupe entre parentheses evite de casser les autres conditions.
                    $builder->where(function ($requete) use ($colonne, $tenantId): void {
                        $requete->where($colonne, $tenantId)->orWhereNull($colonne);
                    });

                    return;
                }

                $builder->where($colonne, $tenantId);

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
            // Creation d'une reference : tenant_id doit rester NULL, quel que
            // soit le tenant courant.
            if ($model->creationReferenceAutorisee) {
                if (! empty($model->tenant_id)) {
                    throw new LogicException(
                        'Une reference ne peut pas avoir de tenant_id : '.$model::class
                    );
                }

                return;
            }

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

        // Protege les references : une entreprise qui les voit ne doit pas
        // pouvoir les modifier ni les supprimer.
        static::updating(function (Model $model): void {
            if ($model::inclutReferences()
                && $model->getOriginal('tenant_id') === null
                && ! $model->modificationReferenceAutorisee
            ) {
                throw new ReferenceNonModifiableException(
                    'Reference non modifiable : '.$model::class.' #'.$model->getKey()
                );
            }
        });

        static::deleting(function (Model $model): void {
            if ($model::inclutReferences()
                && $model->tenant_id === null
                && ! $model->modificationReferenceAutorisee
            ) {
                throw new ReferenceNonModifiableException(
                    'Reference non supprimable : '.$model::class.' #'.$model->getKey()
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
     * Ce modele partage-t-il des lignes « de reference » (tenant_id NULL)
     * visibles par toutes les entreprises ?
     *
     * false par defaut : le modele garde le comportement strict d'origine.
     * A redefinir a true uniquement pour les tables concernees (ex.
     * categories de la Bibliotheque de prix).
     *
     * @return bool
     */
    public static function inclutReferences(): bool
    {
        return false;
    }

    /**
     * Cree une ligne de reference (tenant_id NULL), independante du tenant
     * courant. Reserve aux seeders et commandes d'administration.
     *
     * @param array<string, mixed> $attributs Attributs de la ligne (tenant_id interdit).
     * @return static La reference creee.
     *
     * @throws LogicException Si le modele n'accepte pas de references, ou si
     *         un tenant_id est fourni.
     */
    public static function creerReference(array $attributs): static
    {
        if (! static::inclutReferences()) {
            throw new LogicException(
                static::class.' n\'accepte pas de references : redefinir inclutReferences().'
            );
        }

        if (($attributs['tenant_id'] ?? null) !== null) {
            throw new LogicException('Une reference ne peut pas avoir de tenant_id.');
        }

        $modele = new static();
        $modele->forceFill($attributs);
        $modele->creationReferenceAutorisee = true;

        try {
            $modele->save();
        } finally {
            $modele->creationReferenceAutorisee = false;
        }

        return $modele;
    }

    /**
     * Autorise la modification ou la suppression de CETTE instance, si c'est
     * une reference. Reserve aux seeders et commandes d'administration.
     *
     * @return static
     */
    public function autoriserModificationReference(): static
    {
        $this->modificationReferenceAutorisee = true;

        return $this;
    }

    /**
     * Cette ligne est-elle une reference (partagee, sans entreprise) ?
     *
     * @return bool
     */
    public function estReference(): bool
    {
        return $this->tenant_id === null;
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
