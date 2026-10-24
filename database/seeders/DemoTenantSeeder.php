<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use App\Models\Tenant;

/**
 * Crée un tenant de démonstration (« Mondelice Bâtiment ») et son premier
 * utilisateur, pour pouvoir tester l'application en conditions réelles
 * (connexion, filtrage multi-tenant) sans passer par un vrai processus
 * d'inscription, qui n'existe pas encore (Phase 2).
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create([
            'preferred_language' => 'fr',
        ]);

                $user = new User([
            'name' => 'Démo Mondelice Bâtiment',
            'email' => 'demo@mondelice-batiment.fr',
            'password' => bcrypt('password'),
        ]);
        $user->tenant_id = $tenant->id;
        $user->preferred_language = 'fr';
        $user->save();

        $this->command->info("Tenant de démo créé : {$tenant->id}");    }
}
