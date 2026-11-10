<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tâche 2.14 : test croisé des 6 rôles.
 *
 * Vérifie que chaque rôle porte EXACTEMENT le périmètre de permissions
 * défini dans database/seeders/PermissionSeeder.php --- ni permission
 * manquante (le rôle ne pourrait pas agir dans son périmètre légitime),
 * ni permission excédentaire (le rôle sortirait de son périmètre).
 *
 * Cette matrice attendue est une copie volontaire de celle du seeder,
 * pas un appel au seeder lui-même : si quelqu'un modifie accidentellement
 * la matrice dans PermissionSeeder sans mettre à jour ce test (ou
 * l'inverse), le test échoue et révèle la divergence, au lieu de
 * valider silencieusement un seeder déjà faux.
 */
class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Matrice attendue : {rôle} => [liste des permissions "module.action"].
     * Doit rester synchronisée avec PermissionSeeder::run() et
     * CONTEXTE_PROJET_IA.md section 5.6.
     *
     * @var array<string, array<int, string>>
     */
    private array $expectedMatrix = [
        'Direction' => [
            'clients.voir', 'contacts.voir', 'clients.creer', 'contacts.creer', 'clients.modifier', 'contacts.modifier', 'clients.supprimer', 'contacts.supprimer',
            'opportunites.voir', 'opportunites.creer', 'opportunites.modifier', 'opportunites.supprimer',
            'devis.voir', 'devis.creer', 'devis.modifier', 'devis.supprimer', 'devis.envoyer',
            'bibliotheque.consulter', 'bibliotheque.personnaliser', 'bibliotheque.supprimer', 'bibliotheque.voir_prix_achat', 'bibliotheque.publier_entreprise',
            'chantiers.voir', 'chantiers.creer', 'chantiers.modifier', 'chantiers.supprimer',
            'situations_travaux.voir', 'situations_travaux.creer', 'situations_travaux.modifier', 'situations_travaux.supprimer',
            'factures.voir', 'factures.creer', 'factures.modifier', 'factures.supprimer', 'factures.valider',
            'commandes_fournisseur.voir', 'commandes_fournisseur.creer', 'commandes_fournisseur.modifier', 'commandes_fournisseur.supprimer',
            'planning.voir', 'planning.creer', 'planning.modifier', 'planning.supprimer',
            'pointages.voir', 'pointages.creer', 'pointages.modifier', 'pointages.supprimer', 'pointages.corriger',
            'tickets_sav.voir', 'tickets_sav.creer', 'tickets_sav.modifier', 'tickets_sav.supprimer', 'tickets_sav.cloturer',
            'documents.voir', 'documents.creer', 'documents.modifier', 'documents.supprimer',
            'logs.voir',
            'parametres.gerer',
        ],
        'Commercial' => [
            'clients.voir', 'contacts.voir', 'clients.creer', 'contacts.creer', 'clients.modifier', 'contacts.modifier', 'clients.supprimer', 'contacts.supprimer',
            'opportunites.voir', 'opportunites.creer', 'opportunites.modifier', 'opportunites.supprimer',
            'devis.voir', 'devis.creer', 'devis.modifier', 'devis.supprimer', 'devis.envoyer',
            'bibliotheque.consulter', 'bibliotheque.personnaliser', 'bibliotheque.voir_prix_achat',
            'chantiers.voir',
            'planning.voir',
            'tickets_sav.voir', 'tickets_sav.creer',
            'documents.voir',
        ],
        'Conducteur travaux' => [
            'clients.voir', 'contacts.voir', 'opportunites.voir', 'devis.voir',
            'bibliotheque.consulter', 'bibliotheque.personnaliser', 'bibliotheque.voir_prix_achat', 'bibliotheque.publier_entreprise',
            'chantiers.voir', 'chantiers.creer', 'chantiers.modifier', 'chantiers.supprimer',
            'situations_travaux.voir', 'situations_travaux.creer', 'situations_travaux.modifier', 'situations_travaux.supprimer',
            'factures.voir',
            'commandes_fournisseur.voir', 'commandes_fournisseur.creer', 'commandes_fournisseur.modifier', 'commandes_fournisseur.supprimer',
            'planning.voir', 'planning.creer', 'planning.modifier', 'planning.supprimer',
            'pointages.voir',
            'tickets_sav.voir', 'tickets_sav.creer', 'tickets_sav.modifier', 'tickets_sav.supprimer', 'tickets_sav.cloturer',
            'documents.voir', 'documents.creer', 'documents.modifier',
        ],
        'Administratif' => [
            'clients.voir', 'clients.creer', 'clients.modifier', 'contacts.voir', 'contacts.creer', 'contacts.modifier',
            'devis.voir', 'chantiers.voir', 'situations_travaux.voir',
            'factures.voir', 'factures.creer', 'factures.modifier', 'factures.supprimer', 'factures.valider',
            'commandes_fournisseur.voir',
            'bibliotheque.consulter', 'bibliotheque.voir_prix_achat',
            'parametres.gerer',
            'planning.voir',
            'documents.voir', 'documents.creer', 'documents.modifier',
        ],
        'Salarié terrain' => [
            'planning.voir',
            'pointages.voir', 'pointages.creer',
            'documents.creer',
        ],
        'Ressources Humaines' => [
            'pointages.voir', 'pointages.creer', 'pointages.corriger',
        ],
    ];

    /**
     * Seed les rôles et permissions avant chaque test de cette classe
     * (RefreshDatabase repart d'une base vierge à chaque fois).
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    /**
     * Vérifie, pour chacun des 6 rôles, que l'ensemble de permissions
     * portées correspond exactement (même éléments, sans plus ni moins)
     * à la matrice attendue.
     */
    public function test_each_role_has_exactly_its_expected_permissions(): void
    {
        foreach ($this->expectedMatrix as $roleName => $expectedPermissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            $this->assertNotNull($role, "Le rôle \"{$roleName}\" devrait exister après le seeding.");

            $actualPermissions = $role->permissions->pluck('name')->sort()->values()->all();
            $expectedSorted = collect($expectedPermissions)->sort()->values()->all();

            $this->assertEquals(
                $expectedSorted,
                $actualPermissions,
                "Le périmètre du rôle \"{$roleName}\" ne correspond pas à la matrice attendue."
            );
        }
    }

    /**
     * Vérifie qu'aucun rôle ne porte une permission appartenant à un
     * périmètre qui n'est pas le sien (test croisé proprement dit) :
     * pour chaque permission qu'un rôle NE DEVRAIT PAS avoir, on confirme
     * explicitement son absence. Redondant avec le test précédent
     * (qui est déjà exhaustif) mais formulé dans l'esprit "négatif" de
     * la tâche 2.14, et plus lisible en cas d'échec individuel.
     */
    public function test_no_role_exceeds_its_perimeter(): void
    {
        $allPermissionNames = \Spatie\Permission\Models\Permission::pluck('name')->all();

        foreach ($this->expectedMatrix as $roleName => $expectedPermissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $forbidden = array_diff($allPermissionNames, $expectedPermissions);

            foreach ($forbidden as $permission) {
                $this->assertFalse(
                    $role->hasPermissionTo($permission),
                    "Le rôle \"{$roleName}\" ne devrait PAS avoir la permission \"{$permission}\"."
                );
            }
        }
    }

    /**
     * Vérifie que le total des permissions en base (54) correspond à
     * l'union de toutes les permissions listées dans la matrice --- garde
     * contre une permission orpheline créée par erreur et non attribuée
     * à aucun rôle (invisible aux deux tests précédents).
     */
    public function test_total_permission_count_matches_matrix_union(): void
    {
        $unionFromMatrix = collect($this->expectedMatrix)
            ->flatten()
            ->unique()
            ->sort()
            ->values();

        $this->assertEquals(59, $unionFromMatrix->count());
        $this->assertEquals(59, \Spatie\Permission\Models\Permission::count());
    }
}
