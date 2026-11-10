<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Isolation entre entreprises de la Bibliotheque de prix (tache 3.3) :
 * une entreprise ne voit, ne modifie et n'archive jamais les articles
 * d'une autre, meme en connaissant leur identifiant.
 */
class ArticlePrixIsolationTest extends TestCase
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

    public function test_une_entreprise_ne_voit_ni_ne_modifie_les_articles_d_une_autre(): void
    {
        $proprietaire = $this->creerUtilisateur($this->entreprise, 'Direction');

        $id = $this->agirEn($proprietaire)
            ->postJson('/api/bibliotheque-prix', [
                'reference' => 'ART-SECRET',
                'designation' => 'Article confidentiel',
                'type_article' => 'materiaux',
                'unite' => 'u',
                'prix_force_ht' => 20,
                'taux_tva' => 20,
            ])
            ->assertCreated()
            ->json('id');

        $intrus = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');

        $this->agirEn($intrus)
            ->getJson('/api/bibliotheque-prix')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->agirEn($intrus)->getJson('/api/bibliotheque-prix/' . $id)->assertNotFound();
        $this->agirEn($intrus)
            ->putJson('/api/bibliotheque-prix/' . $id, ['designation' => 'Pirate'])
            ->assertNotFound();
        $this->agirEn($intrus)->deleteJson('/api/bibliotheque-prix/' . $id)->assertNotFound();

        $this->assertDatabaseHas('articles_prix', [
            'id' => $id,
            'designation' => 'Article confidentiel',
            'deleted_at' => null,
        ]);
    }
}
