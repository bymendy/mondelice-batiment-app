<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Ensemence les 6 rôles métier de Mondelice Bâtiment (tâche 2.10).
 *
 * Rôles globaux, non rattachés à un tenant : seule l'attribution à un
 * utilisateur (table pivot model_has_roles, via model_id) est isolée par
 * tenant --- cohérent avec 'teams' => false dans config/permission.php.
 * Idempotent (firstOrCreate) : peut être relancé sans créer de doublons.
 *
 * "Ressources Humaines" ajouté le 15 octobre 2026 : fonction distincte de
 * la Direction et de l'Administratif chez Mondelice Bâtiment --- résout
 * le point ouvert sur l'accès aux pointages d'heures identifié le
 * 12 octobre 2026 (CONTEXTE_PROJET_IA.md, section points ouverts).
 */
class RoleSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $roles = [
            'Direction',
            'Commercial',
            'Conducteur travaux',
            'Administratif',
            'Salarié terrain',
            'Ressources Humaines',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }
    }
}
