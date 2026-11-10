<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Reglage "le Commercial peut modifier la Bibliotheque de prix" (tache 3.4 bis,
 * revu le 10 novembre 2026), prouve par de vraies requetes HTTP.
 *
 * Par defaut le reglage est DESACTIVE : le Commercial consulte seulement. La
 * Direction ou l'Administratif peuvent l'activer. Il ne concerne que le
 * Commercial : Direction et Conducteur travaux ne sont jamais bloques.
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
            'type_article' => 'main_oeuvre',
            'unite' => 'm2',
            'prix_force_ht' => 40,
            'taux_tva' => 10,
        ];
    }

    private function activerPourLeCommercial(): void
    {
        $this->dansEntreprise(
            $this->entreprise,
            fn () => Parametre::ecrire('commercial_peut_modifier_bibliotheque', true)
        );
    }

    public function test_le_commercial_est_bloque_par_defaut(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->article('ART-BLOQUE'))
            ->assertForbidden();

        $this->assertDatabaseMissing('articles_prix', ['reference' => 'ART-BLOQUE']);
    }

    public function test_le_commercial_peut_creer_et_modifier_quand_le_reglage_est_active(): void
    {
        $this->activerPourLeCommercial();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $id = $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->article('ART-OK'))
            ->assertCreated()
            ->json('id');

        $this->agirEn($commercial)
            ->putJson('/api/bibliotheque-prix/' . $id, ['designation' => 'Pose de carrelage 80x80'])
            ->assertOk();
    }

    public function test_le_reglage_d_une_entreprise_ne_change_pas_celui_d_une_autre(): void
    {
        $this->activerPourLeCommercial();
        $autre = $this->creerEntreprise();
        $commercialAutre = $this->creerUtilisateur($autre, 'Commercial');

        $this->agirEn($commercialAutre)
            ->postJson('/api/bibliotheque-prix', $this->article('ART-AUTRE'))
            ->assertForbidden();
    }

    public function test_direction_et_conducteur_ne_sont_jamais_bloques_par_le_reglage(): void
    {
        foreach (['Direction', 'Conducteur travaux'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role);

            $this->agirEn($utilisateur)
                ->postJson('/api/bibliotheque-prix', $this->article('ART-' . str_replace(' ', '', $role)))
                ->assertCreated();
        }
    }

    public function test_l_administratif_consulte_mais_ne_cree_pas(): void
    {
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');

        $this->agirEn($administratif)
            ->postJson('/api/bibliotheque-prix', $this->article('ART-ADMIN'))
            ->assertForbidden();

        $this->agirEn($administratif)->getJson('/api/bibliotheque-prix')->assertOk();
    }

    public function test_le_commercial_peut_toujours_consulter(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)->getJson('/api/bibliotheque-prix')->assertOk();
    }

    public function test_seule_la_direction_archive_un_article(): void
    {
        $this->activerPourLeCommercial();
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $id = $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->article('ART-ARCHIVE'))
            ->json('id');

        foreach (['Commercial', 'Conducteur travaux', 'Administratif'] as $role) {
            $this->agirEn($this->creerUtilisateur($this->entreprise, $role))
                ->deleteJson('/api/bibliotheque-prix/' . $id)
                ->assertForbidden();
        }

        $this->agirEn($direction)->deleteJson('/api/bibliotheque-prix/' . $id)->assertNoContent();
    }
}
