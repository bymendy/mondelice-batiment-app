<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Acces et isolation du module Contact/Client (tache 3.4) : refus sans
 * connexion, refus sans permission, et surtout isolation entre entreprises
 * (une entreprise ne voit, ne modifie et ne supprime jamais les contacts
 * d'une autre).
 */
class ContactAccesTest extends TestCase
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
        $this->getJson('/api/contacts')->assertUnauthorized();
    }

    public function test_un_role_sans_permission_est_refuse(): void
    {
        $terrain = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $this->agirEn($terrain)->getJson('/api/contacts')->assertForbidden();

        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->agirEn($conducteur)
            ->postJson('/api/contacts', ['type' => 'particulier', 'nom' => 'Dupont'])
            ->assertForbidden();

        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');
        $this->agirEn($administratif)
            ->postJson('/api/contacts', ['type' => 'particulier', 'nom' => 'Dupont'])
            ->assertForbidden();
    }

    public function test_le_commercial_peut_creer_un_contact(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->postJson('/api/contacts', ['type' => 'particulier', 'nom' => 'Dupont'])
            ->assertCreated();
    }

    public function test_une_entreprise_ne_voit_ni_ne_modifie_les_contacts_d_une_autre(): void
    {
        $autreEntreprise = $this->creerEntreprise();

        $contact = $this->dansEntreprise(
            $this->entreprise,
            fn () => Contact::create(['type' => 'particulier', 'nom' => 'Dupont'])
        );

        // Temoin : l'entreprise proprietaire voit bien son contact.
        $proprietaire = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->agirEn($proprietaire)
            ->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // L'autre entreprise ne voit rien et ne peut rien toucher.
        $intrus = $this->creerUtilisateur($autreEntreprise, 'Direction');

        $this->agirEn($intrus)
            ->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->agirEn($intrus)->getJson('/api/contacts/' . $contact->id)->assertNotFound();
        $this->agirEn($intrus)
            ->putJson('/api/contacts/' . $contact->id, ['nom' => 'Pirate'])
            ->assertNotFound();
        $this->agirEn($intrus)->deleteJson('/api/contacts/' . $contact->id)->assertNotFound();

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'nom' => 'Dupont',
            'deleted_at' => null,
        ]);
    }
}
