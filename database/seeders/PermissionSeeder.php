<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Ensemence les permissions par module et les attribue aux 6 rôles
 * (tâche 2.11), selon la matrice tranchée le 15 octobre 2026
 * (CONTEXTE_PROJET_IA.md, section 5.6).
 *
 * Convention de nommage : "{module}.{action}", minuscules sans accents.
 * Actions de base : voir, creer, modifier, supprimer. Actions critiques
 * spécifiques à un module : devis.envoyer, factures.valider,
 * tickets_sav.cloturer, pointages.corriger.
 *
 * IMPORTANT --- portée des permissions : ce seeder pose des capacités
 * globales ("peut voir des pointages"), pas un filtrage par ligne
 * ("seulement SES pointages", "seulement SON équipe"). Ce filtrage fin
 * est une Policy Laravel distincte, à écrire à la tâche 2.13 et/ou à la
 * construction réelle de chaque module --- ne pas confondre les deux
 * niveaux.
 *
 * Idempotent (firstOrCreate + syncPermissions) : peut être relancé sans
 * créer de doublons ni accumuler d'anciennes permissions retirées d'un
 * rôle entre deux exécutions.
 */
class PermissionSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $modules = [
            'clients' => ['voir', 'creer', 'modifier', 'supprimer'],
            'contacts' => ['voir', 'creer', 'modifier', 'supprimer'],
            'opportunites' => ['voir', 'creer', 'modifier', 'supprimer'],
            'devis' => ['voir', 'creer', 'modifier', 'supprimer', 'envoyer'],
            'bibliotheque' => ['consulter', 'personnaliser', 'supprimer', 'voir_prix_achat'],
            'chantiers' => ['voir', 'creer', 'modifier', 'supprimer'],
            'situations_travaux' => ['voir', 'creer', 'modifier', 'supprimer'],
            'factures' => ['voir', 'creer', 'modifier', 'supprimer', 'valider'],
            'commandes_fournisseur' => ['voir', 'creer', 'modifier', 'supprimer'],
            'planning' => ['voir', 'creer', 'modifier', 'supprimer'],
            'pointages' => ['voir', 'creer', 'modifier', 'supprimer', 'corriger'],
            'tickets_sav' => ['voir', 'creer', 'modifier', 'supprimer', 'cloturer'],
            'documents' => ['voir', 'creer', 'modifier', 'supprimer'],
            'logs' => ['voir'],
            'parametres' => ['gerer'],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$module}.{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }

        // Module renomme le 10 novembre 2026 (bibliotheque_prix -> bibliotheque) :
        // retire les anciennes permissions (les roles sont resynchronises plus bas).
        Permission::whereIn('name', [
            'bibliotheque_prix.voir', 'bibliotheque_prix.creer',
            'bibliotheque_prix.modifier', 'bibliotheque_prix.supprimer',
        ])->where('guard_name', 'web')->delete();

        $grants = [
            'Direction' => [
                'clients' => ['voir', 'creer', 'modifier', 'supprimer'],
                'contacts' => ['voir', 'creer', 'modifier', 'supprimer'],
                'opportunites' => ['voir', 'creer', 'modifier', 'supprimer'],
                'devis' => ['voir', 'creer', 'modifier', 'supprimer', 'envoyer'],
                'bibliotheque' => ['consulter', 'personnaliser', 'supprimer', 'voir_prix_achat'],
                'chantiers' => ['voir', 'creer', 'modifier', 'supprimer'],
                'situations_travaux' => ['voir', 'creer', 'modifier', 'supprimer'],
                'factures' => ['voir', 'creer', 'modifier', 'supprimer', 'valider'],
                'commandes_fournisseur' => ['voir', 'creer', 'modifier', 'supprimer'],
                'planning' => ['voir', 'creer', 'modifier', 'supprimer'],
                'pointages' => ['voir', 'creer', 'modifier', 'supprimer', 'corriger'],
                'tickets_sav' => ['voir', 'creer', 'modifier', 'supprimer', 'cloturer'],
                'documents' => ['voir', 'creer', 'modifier', 'supprimer'],
                'logs' => ['voir'],
                'parametres' => ['gerer'],
            ],
            'Commercial' => [
                'clients' => ['voir', 'creer', 'modifier', 'supprimer'],
                'contacts' => ['voir', 'creer', 'modifier', 'supprimer'],
                'opportunites' => ['voir', 'creer', 'modifier', 'supprimer'],
                'devis' => ['voir', 'creer', 'modifier', 'supprimer', 'envoyer'],
                'bibliotheque' => ['consulter', 'personnaliser', 'voir_prix_achat'], // personnaliser : soumis au reglage commercial_peut_modifier_bibliotheque (desactive par defaut), voir BibliothequePrixModifiable
                'chantiers' => ['voir'],
                'planning' => ['voir'],
                'tickets_sav' => ['voir', 'creer'],
                'documents' => ['voir'],
            ],
            'Conducteur travaux' => [
                'bibliotheque' => ['consulter', 'personnaliser', 'voir_prix_achat'],
                'clients' => ['voir'],
                'contacts' => ['voir'],
                'opportunites' => ['voir'],
                'devis' => ['voir'],
                'chantiers' => ['voir', 'creer', 'modifier', 'supprimer'],
                'situations_travaux' => ['voir', 'creer', 'modifier', 'supprimer'],
                'factures' => ['voir'],
                'commandes_fournisseur' => ['voir', 'creer', 'modifier', 'supprimer'],
                'planning' => ['voir', 'creer', 'modifier', 'supprimer'],
                'pointages' => ['voir'],
                'tickets_sav' => ['voir', 'creer', 'modifier', 'supprimer', 'cloturer'],
                'documents' => ['voir', 'creer', 'modifier'],
            ],
            'Administratif' => [
                'bibliotheque' => ['consulter', 'voir_prix_achat'],
                'parametres' => ['gerer'],
                'clients' => ['voir', 'creer', 'modifier'],
                'contacts' => ['voir', 'creer', 'modifier'],
                'devis' => ['voir'],
                'chantiers' => ['voir'],
                'situations_travaux' => ['voir'],
                'factures' => ['voir', 'creer', 'modifier', 'supprimer', 'valider'],
                'commandes_fournisseur' => ['voir'],
                'planning' => ['voir'],
                'documents' => ['voir', 'creer', 'modifier'],
            ],
            'Salarié terrain' => [
                'planning' => ['voir'],
                'pointages' => ['voir', 'creer'],
                'documents' => ['creer'],
            ],
            'Ressources Humaines' => [
                'pointages' => ['voir', 'creer', 'corriger'],
            ],
        ];

        foreach ($grants as $roleName => $modulePermissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            $permissionNames = [];
            foreach ($modulePermissions as $module => $actions) {
                foreach ($actions as $action) {
                    $permissionNames[] = "{$module}.{$action}";
                }
            }

            $role->syncPermissions($permissionNames);
        }
    }
}
