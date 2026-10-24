<?php

namespace Tests\Feature\Auth;

use App\Models\Activity;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test de non-régression pour le point ouvert "tenant_id du journal non
 * vérifié en conditions réelles" (Mondelice_Phase2_Backend.tex, §6),
 * corrigé le 24 octobre 2026 : tenancy()->initialize() doit s'exécuter
 * AVANT tout appel à Auth::login(), car ce dernier déclenche l'événement
 * Login de façon synchrone, qui écrit immédiatement dans activity_log.
 * Sans cet ordre, tenant_id reste silencieusement vide sur toute
 * inscription/connexion réelle --- ce test l'empêche de redevenir vrai
 * sans qu'on s'en aperçoive.
 */
class TenantIdentificationOnLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seede les rôles et permissions : la base `testing` est vide par
     * défaut (RefreshDatabase ne rejoue que les migrations, pas les
     * seeders), et RegisteredUserController::store() appelle
     * assignRole('Direction'), qui échoue sans ce seeding préalable.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);

        // Sanctum ne démarre la session (middleware 'web') que s'il
        // reconnaît la requête comme venant du front (stateful) --- ce
        // qui se décide sur l'en-tête Referer/Origin comparé à la liste
        // config('sanctum.stateful'). postJson() ne l'envoie pas par
        // défaut, donc on le fixe ici pour toutes les requêtes du test.
        $this->withHeader('Referer', config('app.url'));
    }

    /**
     * L'inscription doit produire une entrée activity_log avec tenant_id rempli.
     *
     * @return void
     */
    public function test_registration_logs_activity_with_tenant_id(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $response = $this->postJson('/api/register', [
            'name' => 'Test Non-Régression',
            'email' => 'nonregression-register@mondelice.fr',
            'password' => 'MotDePasse123!',
            'password_confirmation' => 'MotDePasse123!',
        ]);

        $response->assertStatus(201);

        $tenantId = $response->json('user.tenant_id');
        $this->assertNotEmpty($tenantId);

        $lastActivity = Activity::query()->latest('id')->first();

        $this->assertNotNull($lastActivity);
        $this->assertSame($tenantId, $lastActivity->tenant_id);
    }

    /**
     * La connexion directe (MFA inactif) doit produire une entrée
     * activity_log avec tenant_id rempli.
     *
     * @return void
     */
    public function test_login_logs_activity_with_tenant_id(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $register = $this->postJson('/api/register', [
            'name' => 'Test Connexion',
            'email' => 'nonregression-login@mondelice.fr',
            'password' => 'MotDePasse123!',
            'password_confirmation' => 'MotDePasse123!',
        ]);

        $tenantId = $register->json('user.tenant_id');

        $this->postJson('/api/logout');

        $response = $this->postJson('/api/login', [
            'email' => 'nonregression-login@mondelice.fr',
            'password' => 'MotDePasse123!',
        ]);

        $response->assertStatus(200);

        $lastActivity = Activity::query()->latest('id')->first();

        $this->assertNotNull($lastActivity);
        $this->assertSame($tenantId, $lastActivity->tenant_id);
    }
}
