<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Clients / CRM, lot 1 : types de client, SIRET unique, contacts
 * (personnes), rattachement client_contact, opposition a la prospection et
 * isolation entre entreprises.
 */
class ClientsEtContactsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private const SIRET = '12345678901234';

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
    }

    /**
     * @param Tenant $entreprise
     * @param array<string, mixed> $attributs
     * @return Client
     */
    private function nouveauClient(Tenant $entreprise, array $attributs = []): Client
    {
        return $this->dansEntreprise(
            $entreprise,
            fn () => Client::create(array_merge(['type' => 'particulier', 'nom' => 'Dupont'], $attributs))
        );
    }

    /**
     * @param Tenant $entreprise
     * @param array<string, mixed> $attributs
     * @return Contact
     */
    private function nouveauContact(Tenant $entreprise, array $attributs = []): Contact
    {
        return $this->dansEntreprise(
            $entreprise,
            fn () => Contact::create(array_merge(['nom' => 'Martin', 'prenom' => 'Luc'], $attributs))
        );
    }

    public function test_les_cinq_types_de_client_sont_acceptes(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->postJson('/api/clients', ['type' => 'particulier', 'nom' => 'Dupont'])
            ->assertCreated();

        foreach (['professionnel', 'syndic', 'prescripteur', 'collectivite'] as $type) {
            $this->agirEn($commercial)
                ->postJson('/api/clients', ['type' => $type, 'raison_sociale' => 'Societe ' . $type])
                ->assertCreated();

            $this->agirEn($commercial)
                ->postJson('/api/clients', ['type' => $type])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('raison_sociale');
        }
    }

    public function test_le_nom_affiche_depend_du_type(): void
    {
        $particulier = $this->nouveauClient($this->entreprise, ['nom' => 'Dupont', 'prenom' => 'Jean']);
        $syndic = $this->nouveauClient($this->entreprise, [
            'type' => 'syndic', 'nom' => null, 'raison_sociale' => 'Syndic Alpha',
        ]);

        $this->assertSame('DUPONT Jean', $particulier->nomAffiche());
        $this->assertSame('Syndic Alpha', $syndic->nomAffiche());
    }

    public function test_le_siret_est_unique_dans_l_entreprise(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $corps = ['type' => 'professionnel', 'raison_sociale' => 'Alpha', 'siret' => self::SIRET];

        $id = $this->agirEn($direction)->postJson('/api/clients', $corps)->assertCreated()->json('id');

        $this->agirEn($direction)
            ->postJson('/api/clients', array_merge($corps, ['raison_sociale' => 'Doublon']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('siret');

        // Modifier un client en gardant son propre SIRET reste possible.
        $this->agirEn($direction)
            ->putJson('/api/clients/' . $id, ['siret' => self::SIRET])
            ->assertOk();

        // Un client archive libere son SIRET.
        $this->agirEn($direction)->deleteJson('/api/clients/' . $id)->assertNoContent();
        $this->agirEn($direction)->postJson('/api/clients', $corps)->assertCreated();
    }

    public function test_deux_entreprises_peuvent_avoir_le_meme_siret(): void
    {
        $autre = $this->creerEntreprise();
        $this->nouveauClient($this->entreprise, ['type' => 'professionnel', 'raison_sociale' => 'A', 'siret' => self::SIRET]);

        $direction = $this->creerUtilisateur($autre, 'Direction');
        $this->agirEn($direction)
            ->postJson('/api/clients', ['type' => 'professionnel', 'raison_sociale' => 'B', 'siret' => self::SIRET])
            ->assertCreated();
    }

    public function test_la_base_refuse_un_siret_en_double(): void
    {
        $this->nouveauClient($this->entreprise, ['type' => 'professionnel', 'raison_sociale' => 'A', 'siret' => self::SIRET]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->nouveauClient($this->entreprise, ['type' => 'professionnel', 'raison_sociale' => 'B', 'siret' => self::SIRET]);
    }

    public function test_le_commercial_cree_un_contact_et_le_rattache(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise, ['type' => 'syndic', 'raison_sociale' => 'Syndic']);

        $contactId = $this->agirEn($commercial)
            ->postJson('/api/contacts', ['nom' => 'Martin', 'prenom' => 'Luc', 'mobile' => '0600000000'])
            ->assertCreated()
            ->json('id');

        $this->agirEn($commercial)
            ->postJson('/api/clients/' . $client->id . '/contacts', [
                'contact_id' => $contactId, 'role' => 'Gardien', 'principal' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('client_contact', [
            'client_id' => $client->id,
            'contact_id' => $contactId,
            'role' => 'Gardien',
            'principal' => true,
            'tenant_id' => $this->entreprise->getTenantKey(),
        ]);
    }

    public function test_un_seul_contact_principal_par_client(): void
    {
        $client = $this->nouveauClient($this->entreprise);
        $premier = $this->nouveauContact($this->entreprise, ['nom' => 'Premier']);
        $second = $this->nouveauContact($this->entreprise, ['nom' => 'Second']);

        $client->rattacherContact($premier, 'Gardien', true);
        $client->rattacherContact($second, 'Comptable', true);

        $this->assertSame(1, DB::table('client_contact')->where('client_id', $client->id)->where('principal', true)->count());
        $this->assertTrue((bool) DB::table('client_contact')->where('contact_id', $second->id)->value('principal'));
        $this->assertFalse((bool) DB::table('client_contact')->where('contact_id', $premier->id)->value('principal'));
    }

    public function test_la_base_refuse_deux_contacts_principaux(): void
    {
        $client = $this->nouveauClient($this->entreprise);
        $a = $this->nouveauContact($this->entreprise, ['nom' => 'A']);
        $b = $this->nouveauContact($this->entreprise, ['nom' => 'B']);
        $tenant = $this->entreprise->getTenantKey();

        DB::table('client_contact')->insert(['tenant_id' => $tenant, 'client_id' => $client->id, 'contact_id' => $a->id, 'principal' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('client_contact')->insert(['tenant_id' => $tenant, 'client_id' => $client->id, 'contact_id' => $b->id, 'principal' => true]);
    }

    public function test_un_contact_peut_servir_plusieurs_clients(): void
    {
        $contact = $this->nouveauContact($this->entreprise);
        $a = $this->nouveauClient($this->entreprise, ['nom' => 'A']);
        $b = $this->nouveauClient($this->entreprise, ['nom' => 'B']);

        $a->rattacherContact($contact);
        $b->rattacherContact($contact, 'Mandataire');

        $this->dansEntreprise($this->entreprise, function () use ($contact, $a): void {
            $this->assertCount(2, $contact->clients()->get());
            $this->assertCount(1, $a->contacts()->get());
        });
    }

    public function test_on_peut_detacher_un_contact_sans_le_supprimer(): void
    {
        $client = $this->nouveauClient($this->entreprise);
        $contact = $this->nouveauContact($this->entreprise);
        $client->rattacherContact($contact);

        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->agirEn($direction)
            ->deleteJson('/api/clients/' . $client->id . '/contacts/' . $contact->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('client_contact', ['client_id' => $client->id, 'contact_id' => $contact->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'deleted_at' => null]);
    }

    public function test_un_contact_d_une_autre_entreprise_n_est_pas_rattachable(): void
    {
        $autre = $this->creerEntreprise();
        $client = $this->nouveauClient($this->entreprise);
        $etranger = $this->nouveauContact($autre);

        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->agirEn($direction)
            ->postJson('/api/clients/' . $client->id . '/contacts', ['contact_id' => $etranger->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_id');

        $this->expectException(LogicException::class);
        $client->rattacherContact($etranger);
    }

    public function test_une_entreprise_ne_voit_pas_les_contacts_d_une_autre(): void
    {
        $autre = $this->creerEntreprise();
        $contact = $this->nouveauContact($this->entreprise);

        $intrus = $this->creerUtilisateur($autre, 'Direction');
        $this->agirEn($intrus)->getJson('/api/contacts')->assertOk()->assertJsonCount(0, 'data');
        $this->agirEn($intrus)->getJson('/api/contacts/' . $contact->id)->assertNotFound();
        $this->agirEn($intrus)->putJson('/api/contacts/' . $contact->id, ['nom' => 'Pirate'])->assertNotFound();
        $this->agirEn($intrus)->deleteJson('/api/contacts/' . $contact->id)->assertNotFound();
    }

    public function test_les_droits_sur_les_contacts_suivent_ceux_des_clients(): void
    {
        $terrain = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $this->agirEn($terrain)->getJson('/api/contacts')->assertForbidden();

        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->agirEn($conducteur)
            ->postJson('/api/contacts', ['nom' => 'Martin'])
            ->assertForbidden();

        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');
        $this->agirEn($administratif)
            ->postJson('/api/contacts', ['nom' => 'Martin'])
            ->assertCreated();

        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $this->agirEn($commercial)->postJson('/api/contacts', ['nom' => 'Martin'])->assertCreated();
    }

    public function test_l_opposition_a_la_prospection_se_cumule_fiche_et_contact(): void
    {
        $libre = $this->nouveauClient($this->entreprise, ['nom' => 'Libre']);
        $oppose = $this->nouveauClient($this->entreprise, ['nom' => 'Oppose', 'ne_plus_demarcher_le' => '2026-11-01']);
        $contactLibre = $this->nouveauContact($this->entreprise);
        $contactOppose = $this->nouveauContact($this->entreprise, ['nom' => 'Refus', 'ne_plus_demarcher_le' => '2026-11-02']);

        $this->assertFalse($libre->prospectionBloquee());
        $this->assertFalse($libre->prospectionBloquee($contactLibre));
        $this->assertTrue($libre->prospectionBloquee($contactOppose));
        $this->assertTrue($oppose->prospectionBloquee());
        $this->assertTrue($oppose->prospectionBloquee($contactLibre));
    }

    public function test_l_opposition_s_enregistre_par_l_api(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise);

        $this->agirEn($commercial)
            ->putJson('/api/clients/' . $client->id, ['ne_plus_demarcher_le' => '2026-11-09'])
            ->assertOk();

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'ne_plus_demarcher_le' => '2026-11-09']);
    }

    public function test_le_schema_est_celui_du_module(): void
    {
        $this->assertTrue(Schema::hasTable('clients'));
        $this->assertTrue(Schema::hasTable('contacts'));
        $this->assertTrue(Schema::hasTable('client_contact'));

        $this->assertTrue(Schema::hasColumn('clients', 'ne_plus_demarcher_le'));
        $this->assertTrue(Schema::hasColumn('contacts', 'ne_plus_demarcher_le'));
        $this->assertFalse(Schema::hasColumn('contacts', 'type'));
        $this->assertFalse(Schema::hasColumn('contacts', 'siret'));

        $this->assertTrue(Schema::hasColumn('opportunites', 'client_id'));
        // Depuis le lot O1, contact_id designe la personne a joindre (table contacts),
        // et non plus l'ancienne fiche renommee en client_id.
        $this->assertTrue(Schema::hasColumn('opportunites', 'contact_id'));
        $this->assertTrue(Schema::hasColumn('quotes', 'client_id'));
        $this->assertFalse(Schema::hasColumn('quotes', 'contact_id'));
    }
}
