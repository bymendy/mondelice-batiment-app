<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * MFA sur les routes metier (decision securite n.1) : obligatoire pour
 * Direction, Administratif et Ressources Humaines, jamais impose au Commercial
 * ni au Conducteur travaux.
 *
 * Les routes testees sont toutes autorisees par permission aux roles testes :
 * un 403 avec "two_factor_setup_required" vient donc bien du MFA, pas d'un
 * manque de droit.
 */
class MfaRoutesMetierTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    /**
     * Routes metier protegees par require.mfa (lecture, autorisee a Direction
     * et Administratif).
     *
     * @var list<string>
     */
    private const ROUTES = ['/api/contacts', '/api/bibliotheque-prix', '/api/parametres'];

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
    }

    public function test_direction_et_administratif_sans_mfa_sont_bloques(): void
    {
        foreach (['Direction', 'Administratif'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role, avecMfa: false);

            foreach (self::ROUTES as $route) {
                $this->agirEn($utilisateur)
                    ->getJson($route)
                    ->assertForbidden()
                    ->assertJsonPath('two_factor_setup_required', true);
            }
        }
    }

    public function test_direction_et_administratif_avec_mfa_accedent_normalement(): void
    {
        foreach (['Direction', 'Administratif'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role, avecMfa: true);

            foreach (self::ROUTES as $route) {
                $this->agirEn($utilisateur)->getJson($route)->assertOk();
            }
        }
    }

    public function test_le_commercial_sans_mfa_n_est_pas_bloque(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial', avecMfa: false);

        $this->agirEn($commercial)->getJson('/api/contacts')->assertOk();
        $this->agirEn($commercial)->getJson('/api/bibliotheque-prix')->assertOk();
    }

    public function test_l_activation_du_mfa_reste_accessible_sans_mfa(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction', avecMfa: false);

        $reponse = $this->agirEn($direction)->postJson('/api/2fa/enable');

        // La route doit exister et ne pas etre verrouillee par le MFA lui-meme,
        // sinon l'utilisateur ne pourrait jamais l'activer.
        $this->assertNotSame(404, $reponse->getStatusCode());
        $reponse->assertJsonMissingPath('two_factor_setup_required');
    }
}
