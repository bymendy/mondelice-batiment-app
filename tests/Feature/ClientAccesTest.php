<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Acces et isolation du module Client (tache 3.4) : refus sans
 * connexion, refus sans permission, et surtout isolation entre entreprises
 * (une entreprise ne voit, ne modifie et ne supprime jamais les contacts
 * d'une autre).
 */
class ClientAccesTest extends TestCase
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

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->getJson('/api/clients')->assertUnauthorized();
    }

    public function test_un_role_sans_permission_est_refuse(): void
    {
        $terrain = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $this->agirEn($terrain)->getJson('/api/clients')->assertForbidden();

        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->agirEn($conducteur)
            ->postJson('/api/clients', ['type' => 'particulier', 'nom' => 'Dupont'])
            ->assertForbidden();
    }

    public function test_l_administratif_cree_et_modifie_mais_ne_supprime_pas(): void
    {
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');

        $id = $this->agirEn($administratif)
            ->postJson('/api/clients', ['type' => 'particulier', 'nom' => 'Dupont'])
            ->assertCreated()
            ->json('id');

        $this->agirEn($administratif)
            ->putJson('/api/clients/' . $id, ['siret' => '12345678901234'])
            ->assertOk();

        $this->agirEn($administratif)->deleteJson('/api/clients/' . $id)->assertForbidden();
    }

    public function test_le_commercial_peut_creer_un_contact(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->postJson('/api/clients', ['type' => 'particulier', 'nom' => 'Dupont'])
            ->assertCreated();
    }

    public function test_une_entreprise_ne_voit_ni_ne_modifie_les_contacts_d_une_autre(): void
    {
        $autreEntreprise = $this->creerEntreprise();

        $contact = $this->dansEntreprise(
            $this->entreprise,
            fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont'])
        );

        // Temoin : l'entreprise proprietaire voit bien son contact.
        $proprietaire = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->agirEn($proprietaire)
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // L'autre entreprise ne voit rien et ne peut rien toucher.
        $intrus = $this->creerUtilisateur($autreEntreprise, 'Direction');

        $this->agirEn($intrus)
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->agirEn($intrus)->getJson('/api/clients/' . $contact->id)->assertNotFound();
        $this->agirEn($intrus)
            ->putJson('/api/clients/' . $contact->id, ['nom' => 'Pirate'])
            ->assertNotFound();
        $this->agirEn($intrus)->deleteJson('/api/clients/' . $contact->id)->assertNotFound();

        $this->assertDatabaseHas('clients', [
            'id' => $contact->id,
            'nom' => 'Dupont',
            'deleted_at' => null,
        ]);
    }
}
