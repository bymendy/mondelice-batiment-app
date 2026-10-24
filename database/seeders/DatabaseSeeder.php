<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Point d'entrée des seeders de l'application.
 *
 * RoleSeeder (tâche 2.10) puis PermissionSeeder (tâche 2.11, dépend des
 * rôles) s'exécutent avant le tenant de démonstration. D'autres seeders
 * (jeu de données complet en Phase 6) viendront s'ajouter ici.
 *
 * Note : ce seeder n'utilise pas WithoutModelEvents, car la génération
 * automatique de l'UUID du tenant et le remplissage automatique de
 * tenant_id (trait BelongsToTenant) dépendent tous deux de l'événement
 * Eloquent "creating".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(DemoTenantSeeder::class);
    }
}
