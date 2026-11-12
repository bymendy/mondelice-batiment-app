<?php

namespace Tests\Feature;

use App\Models\Adresse;
use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Clients / CRM, lot O0 : adresses des clients (facturation et
 * chantiers), recherche par ville, telephone normalise et controle des
 * doublons.
 */
class AdressesEtDoublonsTest extends TestCase
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
     * @param Client $client
     * @param array<string, mixed> $attributs
     * @return Adresse
     */
    private function nouvelleAdresse(Tenant $entreprise, Client $client, array $attributs = []): Adresse
    {
        return $this->dansEntreprise(
            $entreprise,
            fn () => $client->adresses()->create(array_merge([
                'type' => 'chantier', 'ligne1' => '12 rue des Lilas', 'code_postal' => '69003', 'ville' => 'Lyon',
            ], $attributs))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function corpsAdresse(array $surcharge = []): array
    {
        return array_merge([
            'type' => 'chantier', 'libelle' => 'Maison', 'ligne1' => '5 rue Neuve',
            'code_postal' => '75011', 'ville' => 'Paris',
        ], $surcharge);
    }

    public function test_le_schema_est_celui_attendu(): void
    {
        foreach (['client_id', 'type', 'libelle', 'ligne1', 'ligne2', 'code_postal', 'ville', 'pays', 'latitude', 'longitude', 'infos_acces', 'deleted_at', 'tenant_id'] as $colonne) {
            $this->assertTrue(Schema::hasColumn('adresses', $colonne), $colonne);
        }
        foreach (['adresse', 'code_postal', 'ville'] as $colonne) {
            $this->assertFalse(Schema::hasColumn('clients', $colonne), $colonne);
        }
        $this->assertTrue(Schema::hasColumn('clients', 'telephone_normalise'));
    }

    public function test_un_client_peut_etre_cree_avec_son_adresse_de_facturation(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $reponse = $this->agirEn($commercial)->postJson('/api/clients', [
            'type' => 'particulier', 'nom' => 'Durand',
            'adresse_facturation' => ['ligne1' => '8 avenue Foch', 'code_postal' => '75016', 'ville' => 'Paris'],
        ])->assertCreated();

        $reponse->assertJsonPath('adresses.0.type', 'facturation')
            ->assertJsonPath('adresses.0.ville', 'Paris')
            ->assertJsonPath('adresses.0.pays', 'France');

        $this->assertSame(1, $this->dansEntreprise($this->entreprise, fn () => Adresse::query()->count()));
    }

    public function test_un_client_sans_adresse_ne_cree_pas_d_adresse_vide(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)->postJson('/api/clients', [
            'type' => 'particulier', 'nom' => 'Durand', 'adresse_facturation' => ['ligne1' => '', 'ville' => null],
        ])->assertCreated();

        $this->assertSame(0, $this->dansEntreprise($this->entreprise, fn () => Adresse::query()->count()));
    }

    public function test_plusieurs_adresses_de_chantier_mais_une_seule_facturation(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise);

        $this->agirEn($commercial)->postJson("/api/clients/{$client->id}/adresses", $this->corpsAdresse())->assertCreated();
        $this->agirEn($commercial)->postJson("/api/clients/{$client->id}/adresses", $this->corpsAdresse(['libelle' => 'Garage']))->assertCreated();
        $this->agirEn($commercial)->postJson("/api/clients/{$client->id}/adresses", $this->corpsAdresse(['type' => 'facturation']))->assertCreated();

        $this->agirEn($commercial)->postJson("/api/clients/{$client->id}/adresses", $this->corpsAdresse(['type' => 'facturation']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $liste = $this->agirEn($commercial)->getJson("/api/clients/{$client->id}/adresses")->assertOk()->json();
        $this->assertCount(3, $liste);
        $this->assertSame('facturation', $liste[0]['type']);
    }

    public function test_les_champs_obligatoires_sont_controles(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise);

        $this->agirEn($commercial)->postJson("/api/clients/{$client->id}/adresses", ['type' => 'chantier'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ligne1', 'code_postal', 'ville']);

        $this->agirEn($commercial)->postJson("/api/clients/{$client->id}/adresses", $this->corpsAdresse(['type' => 'autre']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_une_adresse_se_modifie_mais_son_type_ne_change_pas(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise);
        $adresse = $this->nouvelleAdresse($this->entreprise, $client);

        $this->agirEn($commercial)->putJson("/api/adresses/{$adresse->id}", [
            'ville' => 'Villeurbanne', 'infos_acces' => 'Code 1234, 2e etage', 'type' => 'facturation',
        ])->assertOk()->assertJsonPath('ville', 'Villeurbanne')->assertJsonPath('type', 'chantier');

        $this->agirEn($commercial)->putJson("/api/adresses/{$adresse->id}", ['ligne1' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('ligne1');
    }

    public function test_une_adresse_supprimee_est_archivee_et_la_facturation_peut_etre_recreee(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise);
        $facturation = $this->nouvelleAdresse($this->entreprise, $client, ['type' => 'facturation']);

        $this->agirEn($commercial)->deleteJson("/api/adresses/{$facturation->id}")->assertNoContent();

        $this->assertSoftDeleted('adresses', ['id' => $facturation->id]);
        $this->agirEn($commercial)->postJson("/api/clients/{$client->id}/adresses", $this->corpsAdresse(['type' => 'facturation']))
            ->assertCreated();
    }

    public function test_la_recherche_de_clients_porte_sur_la_ville_des_adresses(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $lyon = $this->nouveauClient($this->entreprise, ['nom' => 'Alpha']);
        $paris = $this->nouveauClient($this->entreprise, ['nom' => 'Beta']);
        $this->nouvelleAdresse($this->entreprise, $lyon, ['ville' => 'Lyon']);
        $this->nouvelleAdresse($this->entreprise, $paris, ['ville' => 'Paris']);

        $noms = collect($this->agirEn($commercial)->getJson('/api/clients?recherche=lyon')->assertOk()->json('data'))->pluck('nom');

        $this->assertSame(['Alpha'], $noms->all());
    }

    public function test_les_adresses_d_une_autre_entreprise_sont_introuvables(): void
    {
        $autre = $this->creerEntreprise();
        $clientAutre = $this->nouveauClient($autre);
        $adresseAutre = $this->nouvelleAdresse($autre, $clientAutre);
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)->getJson("/api/clients/{$clientAutre->id}/adresses")->assertNotFound();
        $this->agirEn($commercial)->postJson("/api/clients/{$clientAutre->id}/adresses", $this->corpsAdresse())->assertNotFound();
        $this->agirEn($commercial)->putJson("/api/adresses/{$adresseAutre->id}", ['ville' => 'X'])->assertNotFound();
        $this->agirEn($commercial)->deleteJson("/api/adresses/{$adresseAutre->id}")->assertNotFound();
    }

    public function test_les_permissions_sont_appliquees(): void
    {
        $client = $this->nouveauClient($this->entreprise);
        $adresse = $this->nouvelleAdresse($this->entreprise, $client);
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');

        $this->agirEn($conducteur)->getJson("/api/clients/{$client->id}/adresses")->assertOk();
        $this->agirEn($conducteur)->postJson("/api/clients/{$client->id}/adresses", $this->corpsAdresse())->assertForbidden();
        $this->agirEn($conducteur)->putJson("/api/adresses/{$adresse->id}", ['ville' => 'X'])->assertForbidden();
        $this->agirEn($conducteur)->deleteJson("/api/adresses/{$adresse->id}")->assertForbidden();
        $this->agirEn($conducteur)->getJson('/api/doublons/clients?email=a@b.fr')->assertForbidden();
    }

    public function test_le_telephone_normalise_suit_le_telephone(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $id = $this->agirEn($commercial)->postJson('/api/clients', [
            'type' => 'particulier', 'nom' => 'Martin', 'telephone' => '+33 6 12 34 56 78',
        ])->assertCreated()->json('id');

        $this->assertSame('0612345678', $this->dansEntreprise($this->entreprise, fn () => Client::find($id)->telephone_normalise));

        $this->agirEn($commercial)->putJson("/api/clients/{$id}", ['telephone' => '07 11 22 33 44'])->assertOk();
        $this->assertSame('0711223344', $this->dansEntreprise($this->entreprise, fn () => Client::find($id)->telephone_normalise));

        $this->agirEn($commercial)->putJson("/api/clients/{$id}", ['telephone' => null])->assertOk();
        $this->assertNull($this->dansEntreprise($this->entreprise, fn () => Client::find($id)->telephone_normalise));
    }

    public function test_doublon_par_email_sans_tenir_compte_de_la_casse(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise, ['email' => 'Jean.Dupont@Exemple.fr']);

        $this->agirEn($commercial)->getJson('/api/doublons/clients?email=jean.dupont@exemple.fr')
            ->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonPath('data.0.raisons', ['email']);
    }

    public function test_doublon_par_telephone_sous_deux_formats(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise, ['telephone' => '06 12 34 56 78']);

        $this->agirEn($commercial)->getJson('/api/doublons/clients?telephone=' . urlencode('+33 6 12 34 56 78'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonPath('data.0.raisons', ['telephone']);
    }

    public function test_doublon_par_siret(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise, ['type' => 'professionnel', 'nom' => null, 'raison_sociale' => 'Alpha SARL', 'siret' => '12345678901234']);

        $this->agirEn($commercial)->getJson('/api/doublons/clients?siret=' . urlencode('123 456 789 01234'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonPath('data.0.nom', 'Alpha SARL')
            ->assertJsonPath('data.0.raisons', ['siret']);
    }

    public function test_doublon_par_adresse_et_cumul_des_raisons(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient($this->entreprise, ['email' => 'a@b.fr']);
        $this->nouvelleAdresse($this->entreprise, $client, ['ligne1' => '12 Rue  des Lilas', 'code_postal' => '69003']);

        $this->agirEn($commercial)->getJson('/api/doublons/clients?email=a@b.fr&ligne1=' . urlencode('12 rue des lilas') . '&code_postal=69003')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.raisons', ['email', 'adresse']);

        // L'adresse seule, sans code postal, n'est pas testee.
        $this->agirEn($commercial)->getJson('/api/doublons/clients?email=zzz@b.fr&ligne1=' . urlencode('12 rue des lilas'))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_les_doublons_ignorent_les_archives_l_autre_entreprise_et_la_fiche_modifiee(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $archive = $this->nouveauClient($this->entreprise, ['email' => 'archive@x.fr']);
        $this->dansEntreprise($this->entreprise, fn () => $archive->delete());
        $this->nouveauClient($this->creerEntreprise(), ['email' => 'autre@x.fr']);
        $courant = $this->nouveauClient($this->entreprise, ['email' => 'courant@x.fr']);

        foreach (['archive@x.fr', 'autre@x.fr'] as $email) {
            $this->agirEn($commercial)->getJson('/api/doublons/clients?email=' . $email)->assertOk()->assertJsonCount(0, 'data');
        }

        $this->agirEn($commercial)->getJson('/api/doublons/clients?email=courant@x.fr')->assertOk()->assertJsonCount(1, 'data');
        $this->agirEn($commercial)->getJson("/api/doublons/clients?email=courant@x.fr&ignorer_client_id={$courant->id}")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_le_controle_des_doublons_exige_au_moins_un_critere(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)->getJson('/api/doublons/clients')->assertUnprocessable();
    }
}
