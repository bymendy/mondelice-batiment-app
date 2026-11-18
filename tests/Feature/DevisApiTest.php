<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\LotDevis;
use App\Models\Opportunite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D1 : API du devis, de ses lots et de ses lignes,
 * numerotation, droits et visibilite.
 */
class DevisApiTest extends TestCase
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
     * @param callable $action
     * @return mixed
     */
    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    private function nouveauClient(): Client
    {
        return $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));
    }

    /**
     * Devis cree directement en base.
     *
     * @param Client $client
     * @param User|null $auteur
     * @param array<string, mixed> $attributs
     * @return Devis
     */
    private function devisPour(Client $client, ?User $auteur = null, array $attributs = []): Devis
    {
        return $this->chez(fn () => Devis::create(array_merge([
            'client_id' => $client->id,
            'titre' => 'Salle de bain',
            'auteur_id' => $auteur?->id,
            'portee' => 'entreprise',
        ], $attributs)));
    }

    /**
     * @param Devis $devis
     * @param string $statut
     * @return void
     */
    private function poserStatut(Devis $devis, string $statut): void
    {
        $this->chez(fn () => $devis->forceFill(['statut' => $statut])->save());
    }

    /**
     * @param array<string, mixed> $surcharge
     * @return array<string, mixed>
     */
    private function ligne(array $surcharge = []): array
    {
        return array_merge([
            'libelle' => 'Pose de carrelage',
            'quantite' => 2,
            'unite' => 'm2',
            'prix_unitaire_ht' => 100,
        ], $surcharge);
    }

    public function test_un_commercial_cree_un_devis_numerote(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();

        $reponse = $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Cuisine'])
            ->assertCreated()
            ->assertJsonPath('numero', 'DE' . now()->year . '-0001')
            ->assertJsonPath('statut', 'en_preparation')
            ->assertJsonPath('portee', 'prive')
            ->assertJsonPath('validite_jours', 30)
            ->assertJsonPath('auteur_id', $commercial->id)
            ->assertJsonPath('total_ht', '0.00');

        $devis = $this->chez(fn () => Devis::query()->findOrFail($reponse->json('id')));
        $this->assertSame(now()->toDateString(), $devis->date_devis->toDateString());
        $this->assertSame(now()->addDays(30)->toDateString(), $devis->date_expiration->toDateString());

        $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Salon'])
            ->assertCreated()
            ->assertJsonPath('numero', 'DE' . now()->year . '-0002');
    }

    public function test_la_numerotation_est_propre_a_chaque_entreprise(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();
        $autre = $this->creerEntreprise();
        $commercialAutre = $this->creerUtilisateur($autre, 'Commercial');
        $clientAutre = $this->dansEntreprise($autre, fn () => Client::create(['type' => 'particulier', 'nom' => 'Martin']));

        $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'A'])
            ->assertJsonPath('numero', 'DE' . now()->year . '-0001');
        $this->agirEn($commercialAutre)->postJson('/api/devis', ['client_id' => $clientAutre->id, 'titre' => 'B'])
            ->assertJsonPath('numero', 'DE' . now()->year . '-0001');
    }

    public function test_un_numero_supprime_n_est_pas_reutilise(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();

        $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'A'])->assertCreated();
        $deuxieme = $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'B'])->assertCreated();

        $this->agirEn($commercial)->deleteJson('/api/devis/' . $deuxieme->json('id'))->assertNoContent();

        $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'C'])
            ->assertJsonPath('numero', 'DE' . now()->year . '-0003');
    }

    public function test_la_creation_exige_un_client_de_l_entreprise(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $autre = $this->creerEntreprise();
        $etranger = $this->dansEntreprise($autre, fn () => Client::create(['type' => 'particulier', 'nom' => 'Pirate']));

        $this->agirEn($commercial)->postJson('/api/devis', ['titre' => 'Sans client'])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $etranger->id, 'titre' => 'Etranger'])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $this->nouveauClient()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('titre');
    }

    public function test_les_liens_avec_l_affaire_et_l_adresse_sont_controles(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();
        $autreClient = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Durand']));

        $affaireAutreClient = $this->chez(fn () => Opportunite::create([
            'client_id' => $autreClient->id, 'titre' => 'Autre', 'commercial_id' => $commercial->id,
        ]));
        $adresseAutreClient = $this->chez(fn () => $autreClient->adresses()->create([
            'type' => 'chantier', 'ligne1' => '1 rue A', 'code_postal' => '69001', 'ville' => 'Lyon',
        ]));
        $affaire = $this->chez(fn () => Opportunite::create([
            'client_id' => $client->id, 'titre' => 'Cuisine', 'commercial_id' => $commercial->id,
        ]));
        $adresse = $this->chez(fn () => $client->adresses()->create([
            'type' => 'chantier', 'ligne1' => '12 rue des Lilas', 'code_postal' => '69003', 'ville' => 'Lyon',
        ]));

        $this->agirEn($commercial)->postJson('/api/devis', [
            'client_id' => $client->id, 'titre' => 'X',
            'opportunite_id' => $affaireAutreClient->id, 'adresse_chantier_id' => $adresseAutreClient->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['opportunite_id', 'adresse_chantier_id']);

        $this->agirEn($commercial)->postJson('/api/devis', [
            'client_id' => $client->id, 'titre' => 'Cuisine',
            'opportunite_id' => $affaire->id, 'adresse_chantier_id' => $adresse->id,
        ])->assertCreated()
            ->assertJsonPath('opportunite_id', $affaire->id)
            ->assertJsonPath('adresse_chantier_id', $adresse->id);
    }

    public function test_les_droits_suivent_les_roles(): void
    {
        $client = $this->nouveauClient();
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');
        $devis = $this->devisPour($client);

        foreach ([$conducteur, $administratif] as $lecteur) {
            $this->agirEn($lecteur)->getJson('/api/devis')->assertOk();
            $this->agirEn($lecteur)->getJson("/api/devis/{$devis->id}")->assertOk();
            $this->agirEn($lecteur)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'X'])->assertForbidden();
            $this->agirEn($lecteur)->putJson("/api/devis/{$devis->id}", ['titre' => 'Y'])->assertForbidden();
            $this->agirEn($lecteur)->deleteJson("/api/devis/{$devis->id}")->assertForbidden();
            $this->agirEn($lecteur)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne())->assertForbidden();
            $this->agirEn($lecteur)->postJson("/api/devis/{$devis->id}/lots", ['type' => 'lot', 'libelle' => 'Gros oeuvre'])->assertForbidden();
        }

    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->getJson('/api/devis')->assertUnauthorized();
        $this->postJson('/api/devis', ['titre' => 'X'])->assertUnauthorized();
    }

    public function test_un_devis_prive_n_est_visible_que_de_son_auteur_et_de_la_direction(): void
    {
        $client = $this->nouveauClient();
        $auteur = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $prive = $this->devisPour($client, $auteur, ['portee' => 'prive']);
        $partage = $this->devisPour($client, $auteur, ['portee' => 'entreprise', 'titre' => 'Partage']);

        $this->agirEn($auteur)->getJson('/api/devis')->assertJsonCount(2, 'data');
        $this->agirEn($direction)->getJson('/api/devis')->assertJsonCount(2, 'data');
        $this->agirEn($collegue)->getJson('/api/devis')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $partage->id);

        $this->agirEn($collegue)->getJson("/api/devis/{$prive->id}")->assertNotFound();
        $this->agirEn($collegue)->putJson("/api/devis/{$prive->id}", ['titre' => 'Vol'])->assertNotFound();
        $this->agirEn($collegue)->deleteJson("/api/devis/{$prive->id}")->assertNotFound();
        $this->agirEn($collegue)->postJson("/api/devis/{$prive->id}/lignes", $this->ligne())->assertNotFound();
        $this->agirEn($direction)->getJson("/api/devis/{$prive->id}")->assertOk();
        $this->agirEn($collegue)->getJson("/api/devis/{$partage->id}")->assertOk();
    }

    public function test_les_devis_d_une_autre_entreprise_sont_introuvables(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $autre = $this->creerEntreprise();
        $clientEtranger = $this->dansEntreprise($autre, fn () => Client::create(['type' => 'particulier', 'nom' => 'Pirate']));
        $devisEtranger = $this->dansEntreprise($autre, fn () => Devis::create(['client_id' => $clientEtranger->id, 'titre' => 'Secret', 'portee' => 'entreprise']));

        $this->agirEn($direction)->getJson('/api/devis')->assertJsonCount(0, 'data');
        $this->agirEn($direction)->getJson("/api/devis/{$devisEtranger->id}")->assertNotFound();
        $this->agirEn($direction)->postJson("/api/devis/{$devisEtranger->id}/lignes", $this->ligne())->assertNotFound();
    }

    public function test_la_modification_garde_le_client_et_recalcule_l_expiration(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();
        $autreClient = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Durand']));
        $devis = $this->devisPour($client, $commercial);

        $this->agirEn($commercial)->putJson("/api/devis/{$devis->id}", [
            'titre' => 'Nouvelle salle de bain',
            'portee' => 'prive',
            'date_devis' => '2026-12-01',
            'validite_jours' => 15,
            'client_id' => $autreClient->id,
        ])->assertOk()->assertJsonPath('titre', 'Nouvelle salle de bain')->assertJsonPath('portee', 'prive');

        $relu = $this->chez(fn () => Devis::query()->findOrFail($devis->id));
        $this->assertSame($client->id, $relu->client_id);
        $this->assertSame('2026-12-16', $relu->date_expiration->toDateString());

        $this->agirEn($commercial)->putJson("/api/devis/{$devis->id}", ['portee' => 'secret', 'validite_jours' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['portee', 'validite_jours']);
    }

    public function test_les_lots_se_creent_se_modifient_et_se_suppriment(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPour($this->nouveauClient(), $commercial);

        $lot = $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lots", ['type' => 'lot', 'libelle' => 'Gros oeuvre'])
            ->assertCreated()->assertJsonPath('ordre', 1)->assertJsonPath('type', 'lot');
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lots", ['type' => 'emplacement', 'libelle' => 'Cuisine'])
            ->assertCreated()->assertJsonPath('ordre', 2);
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lots", ['type' => 'piece', 'libelle' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->agirEn($commercial)->putJson('/api/lots-devis/' . $lot->json('id'), ['libelle' => 'Second oeuvre', 'ordre' => 5])
            ->assertOk()->assertJsonPath('libelle', 'Second oeuvre')->assertJsonPath('ordre', 5);
        $this->agirEn($commercial)->putJson('/api/lots-devis/' . $lot->json('id'), ['type' => 'emplacement'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->agirEn($commercial)->deleteJson('/api/lots-devis/' . $lot->json('id'))->assertNoContent();
        $this->assertSame(1, $this->chez(fn () => LotDevis::query()->count()));
    }

    public function test_un_lot_qui_contient_des_lignes_ne_se_supprime_pas(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPour($this->nouveauClient(), $commercial);
        $lot = $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lots", ['type' => 'lot', 'libelle' => 'Gros oeuvre'])->json('id');

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne(['lot_id' => $lot]))->assertCreated();

        $this->agirEn($commercial)->deleteJson("/api/lots-devis/{$lot}")
            ->assertUnprocessable()->assertJsonValidationErrors('lot');
    }

    public function test_une_ligne_recalcule_les_totaux_du_devis(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPour($this->nouveauClient(), $commercial);

        $ligne = $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne())
            ->assertCreated()
            ->assertJsonPath('taux_tva', '20.00')
            ->assertJsonPath('ordre', 1)
            ->assertJsonPath('totaux_devis.total_ht', '200.00')
            ->assertJsonPath('totaux_devis.total_tva', '40.00')
            ->assertJsonPath('totaux_devis.total_ttc', '240.00');

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne([
            'libelle' => 'Peinture', 'quantite' => 10, 'prix_unitaire_ht' => 50, 'taux_tva' => 10,
        ]))->assertCreated()->assertJsonPath('totaux_devis.total_ht', '700.00')->assertJsonPath('totaux_devis.total_tva', '90.00');

        $this->agirEn($commercial)->putJson('/api/lignes-devis/' . $ligne->json('id'), ['quantite' => 3])
            ->assertOk()->assertJsonPath('totaux_devis.total_ht', '800.00');

        $fiche = $this->agirEn($commercial)->getJson("/api/devis/{$devis->id}")->assertOk()
            ->assertJsonCount(2, 'lignes')
            ->assertJsonPath('total_ttc', '910.00')
            ->assertJsonCount(2, 'detail_totaux.par_taux');
        $this->assertSame(800.0, (float) $fiche->json('total_ht'));

        $this->agirEn($commercial)->deleteJson('/api/lignes-devis/' . $ligne->json('id'))
            ->assertOk()->assertJsonPath('totaux_devis.total_ht', '500.00');
    }

    public function test_les_lignes_invalides_sont_refusees(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPour($this->nouveauClient(), $commercial);
        $autreDevis = $this->devisPour($this->nouveauClient(), $commercial, ['titre' => 'Autre']);
        $lotAutre = $this->chez(fn () => LotDevis::create(['devis_id' => $autreDevis->id, 'type' => 'lot', 'libelle' => 'Ailleurs']));
        $emplacement = $this->chez(fn () => LotDevis::create(['devis_id' => $devis->id, 'type' => 'emplacement', 'libelle' => 'Cuisine']));

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['libelle', 'quantite', 'prix_unitaire_ht']);
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne(['prix_unitaire_ht' => -5, 'taux_tva' => 150]))
            ->assertUnprocessable()->assertJsonValidationErrors(['prix_unitaire_ht', 'taux_tva']);
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne(['lot_id' => $lotAutre->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('lot_id');
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne(['lot_id' => $emplacement->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('lot_id');
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne(['emplacement_id' => $emplacement->id]))
            ->assertCreated()->assertJsonPath('emplacement_id', $emplacement->id);
    }

    public function test_une_quantite_negative_est_une_deduction(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPour($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne())->assertCreated();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne(['libelle' => 'Remise', 'quantite' => -1, 'prix_unitaire_ht' => 50]))
            ->assertCreated()->assertJsonPath('totaux_devis.total_ht', '150.00');
    }

    public function test_le_prix_de_revient_ne_se_saisit_ni_ne_se_lit(): void
    {
        // Depuis le lot D4a : vrai seulement sans la permission devis.voir_marge.
        $roleSansMarge = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Sans marge', 'guard_name' => 'web']);
        $roleSansMarge->syncPermissions(['devis.voir', 'devis.modifier', 'bibliotheque.consulter']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Sans marge');
        $devis = $this->devisPour($this->nouveauClient(), $commercial);

        $cree = $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne(['prix_revient_unitaire' => 40, 'article_prix_id' => 1]))
            ->assertCreated()->assertJsonMissingPath('prix_revient_unitaire');
        $this->assertNull($this->chez(fn () => LigneDevis::query()->findOrFail($cree->json('id')))->prix_revient_unitaire);

        $this->chez(fn () => LigneDevis::query()->whereKey($cree->json('id'))->firstOrFail()->forceFill(['prix_revient_unitaire' => 40])->save());
        $this->agirEn($commercial)->getJson("/api/devis/{$devis->id}")->assertOk()->assertJsonMissingPath('lignes.0.prix_revient_unitaire');
    }

    public function test_un_devis_envoye_n_est_plus_modifiable(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPour($this->nouveauClient(), $commercial);
        $ligne = $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne())->json('id');
        $lot = $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lots", ['type' => 'lot', 'libelle' => 'Gros oeuvre'])->json('id');

        $this->poserStatut($devis, 'envoye');

        $this->agirEn($commercial)->putJson("/api/devis/{$devis->id}", ['titre' => 'Y'])->assertUnprocessable()->assertJsonValidationErrors('devis');
        $this->agirEn($commercial)->deleteJson("/api/devis/{$devis->id}")->assertUnprocessable()->assertJsonValidationErrors('devis');
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", $this->ligne())->assertUnprocessable();
        $this->agirEn($commercial)->putJson("/api/lignes-devis/{$ligne}", ['quantite' => 9])->assertUnprocessable();
        $this->agirEn($commercial)->deleteJson("/api/lignes-devis/{$ligne}")->assertUnprocessable();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lots", ['type' => 'lot', 'libelle' => 'X'])->assertUnprocessable();
        $this->agirEn($commercial)->putJson("/api/lots-devis/{$lot}", ['libelle' => 'X'])->assertUnprocessable();
        $this->agirEn($commercial)->deleteJson("/api/lots-devis/{$lot}")->assertUnprocessable();

        $this->agirEn($commercial)->getJson("/api/devis/{$devis->id}")->assertOk()->assertJsonPath('total_ht', '200.00');
    }

    public function test_la_suppression_archive_le_devis(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPour($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->deleteJson("/api/devis/{$devis->id}")->assertNoContent();
        $this->agirEn($commercial)->getJson("/api/devis/{$devis->id}")->assertNotFound();
        $this->assertSame(1, $this->chez(fn () => Devis::withTrashed()->count()));
    }

    public function test_les_filtres_de_la_liste(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $dupont = $this->nouveauClient();
        $martin = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Martin']));
        $this->devisPour($dupont, $direction, ['titre' => 'Salle de bain', 'numero' => 'DE2026-0001']);
        $this->devisPour($martin, $direction, ['titre' => 'Toiture', 'numero' => 'DE2026-0002']);
        $envoye = $this->devisPour($martin, $direction, ['titre' => 'Facade', 'numero' => 'DE2026-0003']);
        $this->poserStatut($envoye, 'envoye');

        $this->agirEn($direction)->getJson('/api/devis')->assertJsonCount(3, 'data');
        $this->agirEn($direction)->getJson("/api/devis?client_id={$martin->id}")->assertJsonCount(2, 'data');
        $this->agirEn($direction)->getJson('/api/devis?statut=envoye')->assertJsonCount(1, 'data')->assertJsonPath('data.0.titre', 'Facade');
        $this->agirEn($direction)->getJson('/api/devis?recherche=toiture')->assertJsonCount(1, 'data');
        $this->agirEn($direction)->getJson('/api/devis?recherche=0003')->assertJsonCount(1, 'data');
        $this->agirEn($direction)->getJson('/api/devis?recherche=martin')->assertJsonCount(2, 'data');
        $this->agirEn($direction)->getJson('/api/devis?par_page=2')->assertJsonCount(2, 'data')->assertJsonPath('total', 3);
    }
}
