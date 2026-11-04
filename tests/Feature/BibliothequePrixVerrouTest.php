<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Verrou "le Commercial peut modifier la Bibliotheque de prix" (tache 3.4 bis),
 * prouve par de vraies requetes HTTP : le 403 doit se produire pour le
 * Commercial quand le reglage est coupe, jamais pour Direction/Administratif.
 */
class BibliothequePrixVerrouTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
    }

    /**
     * @param string $reference
     * @return array<string, mixed>
     */
    private function article(string $reference): array
    {
        return [
            'reference' => $reference,
            'designation' => 'Pose de carrelage 60x60',
            'categorie' => 'main_oeuvre',
            'unite' => 'm2',
            'prix_achat_ht' => 25,
            'prix_vente_ht' => 40,
            'taux_tva' => 10,
        ];
    }

    private function verrouiller(): void
    {
        $this->dansEntreprise(
            $this->entreprise,
            fn () => Parametre::ecrire('commercial_peut_modifier_bibliotheque', false)
        );
    }

    public function test_le_commercial_peut_creer_un_article_quand_le_verrou_est_inactif(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->article('ART-OK'))
            ->assertCreated();
    }

    public function test_le_commercial_est_bloque_quand_le_verrou_est_actif(): void
    {
        $this->verrouiller();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->article('ART-BLOQUE'))
            ->assertForbidden();

        $this->assertDatabaseMissing('articles_prix', ['reference' => 'ART-BLOQUE']);
    }

    public function test_direction_et_administratif_ne_sont_jamais_bloques_par_le_verrou(): void
    {
        $this->verrouiller();

        foreach (['Direction', 'Administratif'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role);

            $this->agirEn($utilisateur)
                ->postJson('/api/bibliotheque-prix', $this->article('ART-' . $role))
                ->assertCreated();
        }
    }

    public function test_le_commercial_peut_toujours_consulter_quand_le_verrou_est_actif(): void
    {
        $this->verrouiller();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->getJson('/api/bibliotheque-prix')
            ->assertOk();
    }

    public function test_le_commercial_ne_peut_jamais_archiver_un_article(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $id = $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->article('ART-ARCHIVE'))
            ->json('id');

        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->deleteJson('/api/bibliotheque-prix/' . $id)
            ->assertForbidden();
    }
}
