<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Reglages de l'entreprise (tache 3.4 bis) : acces reserve a Direction et
 * Administratif, validation des valeurs, et isolation entre entreprises.
 */
class ParametresTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private const CLE = 'commercial_peut_modifier_bibliotheque';

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
    }

    public function test_le_commercial_n_a_pas_acces_aux_reglages(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)->getJson('/api/parametres')->assertForbidden();
        $this->agirEn($commercial)
            ->putJson('/api/parametres/' . self::CLE, ['valeur' => false])
            ->assertForbidden();
    }

    public function test_l_administratif_peut_lire_et_modifier_un_reglage(): void
    {
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');

        $this->agirEn($administratif)
            ->getJson('/api/parametres')
            ->assertOk()
            ->assertJsonFragment(['cle' => self::CLE, 'valeur' => false, 'defaut' => false]);

        $this->agirEn($administratif)
            ->putJson('/api/parametres/' . self::CLE, ['valeur' => true])
            ->assertOk();

        $valeur = $this->dansEntreprise($this->entreprise, fn () => Parametre::lire(self::CLE));
        $this->assertTrue($valeur);
    }

    public function test_une_valeur_invalide_ou_une_cle_inconnue_est_refusee(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $this->agirEn($direction)
            ->putJson('/api/parametres/' . self::CLE, ['valeur' => 'pas un booleen'])
            ->assertUnprocessable();

        $this->agirEn($direction)
            ->putJson('/api/parametres/cle_inexistante', ['valeur' => true])
            ->assertNotFound();
    }

    public function test_un_reglage_modifie_ne_change_pas_celui_d_une_autre_entreprise(): void
    {
        $autreEntreprise = $this->creerEntreprise();
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $this->agirEn($direction)
            ->putJson('/api/parametres/' . self::CLE, ['valeur' => true])
            ->assertOk();

        $this->assertTrue($this->dansEntreprise($this->entreprise, fn () => Parametre::lire(self::CLE)));
        $this->assertFalse($this->dansEntreprise($autreEntreprise, fn () => Parametre::lire(self::CLE)));
    }
}
