<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Outils de test multi-entreprises (taches 3.3 a 3.4 bis).
 *
 * Point d'attention : en production, chaque requete HTTP repart d'un etat
 * vierge ; dans un test, le tenant initialise par une requete reste
 * initialise pour la suivante. agirEn() le remet donc a zero avant chaque
 * requete, sinon deux utilisateurs de deux entreprises partageraient le
 * meme tenant et l'isolation serait faussement "prouvee".
 */
trait InteractsWithTenants
{
    /**
     * Seede les roles et permissions (la base `testing` est vide, comme
     * dans TenantIdentificationOnLoginTest).
     *
     * @return void
     */
    protected function seederRolesEtPermissions(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Cree une entreprise cliente (tenant).
     *
     * @return Tenant
     */
    protected function creerEntreprise(): Tenant
    {
        return Tenant::create();
    }

    /**
     * Cree un utilisateur rattache a une entreprise, avec un role.
     *
     * @param Tenant $entreprise Entreprise de l'utilisateur.
     * @param string $role Nom exact du role (ex. "Commercial").
     * @param bool $avecMfa true (defaut) : le MFA est marque comme actif, ce qui
     *        permet aux roles qui l'exigent (Direction, Administratif, RH) de
     *        passer le middleware require.mfa ; false pour tester le blocage.
     * @return User
     */
    protected function creerUtilisateur(Tenant $entreprise, string $role, bool $avecMfa = true): User
    {
        $utilisateur = new User([
            'name' => 'Test ' . $role,
            'email' => Str::random(12) . '@test.mondelice.fr',
            'password' => 'MotDePasse123!',
        ]);
        $utilisateur->tenant_id = $entreprise->getTenantKey();
        if ($avecMfa) {
            // two_factor_confirmed_at est la source de verite du statut "MFA actif".
            $utilisateur->forceFill(['two_factor_confirmed_at' => now()]);
        }
        $utilisateur->save();
        $utilisateur->assignRole($role);

        return $utilisateur;
    }

    /**
     * Connecte l'utilisateur (session, comme le front) apres avoir remis le
     * contexte tenant a zero.
     *
     * @param User $utilisateur
     * @return static
     */
    protected function agirEn(User $utilisateur): static
    {
        tenancy()->end();

        // Le garde "sanctum" met en cache le premier utilisateur resolu ; sans
        // cette remise a zero, toutes les requetes suivantes du test seraient
        // executees avec le premier utilisateur.
        auth()->forgetGuards();

        return $this->actingAs($utilisateur, 'web');
    }

    /**
     * Execute une action dans le contexte d'une entreprise (ex. preparer des
     * donnees), puis remet le contexte a zero.
     *
     * @param Tenant $entreprise
     * @param callable $action
     * @return mixed Valeur renvoyee par l'action.
     */
    protected function dansEntreprise(Tenant $entreprise, callable $action): mixed
    {
        tenancy()->initialize($entreprise);

        try {
            return $action();
        } finally {
            tenancy()->end();
        }
    }
}
