<?php

namespace Tests\Feature;

use App\Models\Adresse;
use App\Models\Client;
use App\Models\Contact;
use App\Models\EtapePipeline;
use App\Models\Motif;
use App\Models\Opportunite;
use App\Models\OpportuniteEtape;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Clients / CRM, lot O2 : CRUD des affaires, changement d'etape a la
 * main et ses regles, historique, visibilite par commercial.
 */
class OpportunitesTest extends TestCase
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

    private function etape(string $code): EtapePipeline
    {
        return $this->chez(fn () => EtapePipeline::query()->where('code', $code)->firstOrFail());
    }

    private function motif(string $type): Motif
    {
        return $this->chez(fn () => Motif::query()->where('type', $type)->firstOrFail());
    }

    private function nouveauClient(): Client
    {
        return $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));
    }

    /**
     * Affaire creee directement en base, rattachee a un commercial.
     *
     * @param Client $client
     * @param User|null $commercial
     * @param array<string, mixed> $attributs
     * @return Opportunite
     */
    private function affaire(Client $client, ?User $commercial = null, array $attributs = []): Opportunite
    {
        return $this->chez(fn () => Opportunite::create(array_merge([
            'client_id' => $client->id,
            'titre' => 'Salle de bain',
            'commercial_id' => $commercial?->id,
            'prochaine_action' => 'Appeler le client',
            'prochaine_action_le' => now()->addDays(3)->toDateString(),
        ], $attributs)));
    }

    /**
     * @param Client $client
     * @param array<string, mixed> $surcharge
     * @return array<string, mixed>
     */
    private function donnees(Client $client, array $surcharge = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'titre' => 'Rénovation cuisine',
            'prochaine_action' => 'Appeler le client',
            'prochaine_action_le' => now()->addDays(3)->toDateString(),
        ], $surcharge);
    }

    public function test_un_commercial_cree_une_affaire_a_l_etape_nouveau(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();

        $this->agirEn($commercial)->postJson('/api/opportunites', $this->donnees($client, [
            'metiers' => ['Plomberie', 'Carrelage'], 'montant_estime' => 12500.5,
        ]))->assertCreated()
            ->assertJsonPath('etape.code', 'nouveau')
            ->assertJsonPath('probabilite', 10)
            ->assertJsonPath('commercial_id', $commercial->id)
            ->assertJsonPath('metiers', ['Plomberie', 'Carrelage']);

        $this->chez(fn () => $this->assertSame(1, Opportunite::query()->count()));
    }

    public function test_la_creation_exige_client_titre_et_prochaine_action(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)->postJson('/api/opportunites', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'titre', 'prochaine_action', 'prochaine_action_le']);
    }

    public function test_les_liens_d_une_autre_entreprise_ou_d_un_autre_client_sont_refuses(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();
        $autreClient = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Martin']));

        $autre = $this->creerEntreprise();
        $etranger = $this->dansEntreprise($autre, fn () => Client::create(['type' => 'particulier', 'nom' => 'Pirate']));
        $sourceEtrangere = $this->dansEntreprise($autre, fn () => Source::query()->firstOrFail());
        $utilisateurEtranger = $this->creerUtilisateur($autre, 'Commercial');

        $contactLibre = $this->chez(fn () => Contact::create(['nom' => 'Libre', 'prenom' => 'Luc']));
        $adresseAutreClient = $this->chez(fn () => $autreClient->adresses()->create([
            'type' => 'chantier', 'ligne1' => '1 rue A', 'code_postal' => '69001', 'ville' => 'Lyon',
        ]));

        $this->agirEn($commercial)->postJson('/api/opportunites', $this->donnees($etranger))
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->agirEn($commercial)->postJson('/api/opportunites', $this->donnees($client, [
            'source_id' => $sourceEtrangere->id,
            'contact_id' => $contactLibre->id,
            'adresse_chantier_id' => $adresseAutreClient->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['source_id', 'contact_id', 'adresse_chantier_id']);

        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->agirEn($direction)->postJson('/api/opportunites', $this->donnees($client, ['commercial_id' => $utilisateurEtranger->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('commercial_id');
    }

    public function test_les_liens_valides_sont_acceptes(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();
        $contact = $this->chez(function () use ($client) {
            $contact = Contact::create(['nom' => 'Martin', 'prenom' => 'Luc']);
            $client->rattacherContact($contact);

            return $contact;
        });
        $adresse = $this->chez(fn () => $client->adresses()->create([
            'type' => 'chantier', 'ligne1' => '12 rue des Lilas', 'code_postal' => '69003', 'ville' => 'Lyon',
        ]));
        $source = $this->chez(fn () => Source::query()->firstOrFail());

        $this->agirEn($commercial)->postJson('/api/opportunites', $this->donnees($client, [
            'contact_id' => $contact->id, 'adresse_chantier_id' => $adresse->id, 'source_id' => $source->id,
        ]))->assertCreated()
            ->assertJsonPath('contact_id', $contact->id)
            ->assertJsonPath('adresse_chantier_id', $adresse->id);
    }

    public function test_l_etape_de_depart_doit_etre_ouverte_et_manuelle(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();

        foreach (['devis_envoye', 'negociation', 'gagne', 'perdu', 'sans_suite'] as $code) {
            $this->agirEn($commercial)->postJson('/api/opportunites', $this->donnees($client, ['etape_id' => $this->etape($code)->id]))
                ->assertUnprocessable()->assertJsonValidationErrors('etape_id');
        }

        $this->agirEn($commercial)->postJson('/api/opportunites', $this->donnees($client, ['etape_id' => $this->etape('visite_faite')->id]))
            ->assertCreated()->assertJsonPath('etape.code', 'visite_faite')->assertJsonPath('probabilite', 35);
    }

    public function test_un_commercial_ne_voit_que_ses_affaires(): void
    {
        $alice = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $bruno = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $client = $this->nouveauClient();
        $affaireAlice = $this->affaire($client, $alice, ['titre' => 'Toiture']);
        $affaireBruno = $this->affaire($client, $bruno, ['titre' => 'Cuisine']);

        $this->agirEn($alice)->getJson('/api/opportunites')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Toiture');
        $this->agirEn($alice)->getJson("/api/opportunites/{$affaireAlice->id}")->assertOk();
        $this->agirEn($alice)->getJson("/api/opportunites/{$affaireBruno->id}")->assertNotFound();
        $this->agirEn($alice)->putJson("/api/opportunites/{$affaireBruno->id}", ['titre' => 'Vol'])->assertNotFound();
        $this->agirEn($alice)->deleteJson("/api/opportunites/{$affaireBruno->id}")->assertNotFound();
        $this->agirEn($alice)->postJson("/api/opportunites/{$affaireBruno->id}/etape", ['etape_id' => $this->etape('a_qualifier')->id])->assertNotFound();
        $this->agirEn($alice)->getJson('/api/opportunites?commercial_id=' . $bruno->id)->assertJsonCount(1, 'data');

        $this->agirEn($direction)->getJson('/api/opportunites')->assertOk()->assertJsonCount(2, 'data');
        $this->agirEn($direction)->getJson('/api/opportunites?commercial_id=' . $bruno->id)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Cuisine');
    }

    public function test_les_affaires_d_une_autre_entreprise_sont_introuvables(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $autre = $this->creerEntreprise();
        $clientEtranger = $this->dansEntreprise($autre, fn () => Client::create(['type' => 'particulier', 'nom' => 'Pirate']));
        $affaireEtrangere = $this->dansEntreprise($autre, fn () => Opportunite::create(['client_id' => $clientEtranger->id, 'titre' => 'Secret']));

        $this->agirEn($direction)->getJson('/api/opportunites')->assertJsonCount(0, 'data');
        $this->agirEn($direction)->getJson("/api/opportunites/{$affaireEtrangere->id}")->assertNotFound();
    }

    public function test_un_commercial_ne_peut_pas_confier_une_affaire_a_un_autre(): void
    {
        $alice = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $bruno = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $client = $this->nouveauClient();

        $this->agirEn($alice)->postJson('/api/opportunites', $this->donnees($client, ['commercial_id' => $bruno->id]))
            ->assertCreated()->assertJsonPath('commercial_id', $alice->id);

        $this->agirEn($direction)->postJson('/api/opportunites', $this->donnees($client, ['commercial_id' => $bruno->id]))
            ->assertCreated()->assertJsonPath('commercial_id', $bruno->id);
    }

    public function test_les_permissions_sont_respectees(): void
    {
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $client = $this->nouveauClient();
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $affaire = $this->affaire($client, $direction);

        $this->agirEn($conducteur)->getJson('/api/opportunites')->assertOk();
        $this->agirEn($conducteur)->postJson('/api/opportunites', $this->donnees($client))->assertForbidden();
        $this->agirEn($conducteur)->putJson("/api/opportunites/{$affaire->id}", ['titre' => 'X'])->assertForbidden();
        $this->agirEn($conducteur)->deleteJson("/api/opportunites/{$affaire->id}")->assertForbidden();
        $this->agirEn($conducteur)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('a_qualifier')->id])->assertForbidden();
    }

    public function test_la_modification_garde_la_prochaine_action_d_une_affaire_ouverte(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->putJson("/api/opportunites/{$affaire->id}", ['titre' => 'Salle de bain complète', 'montant_estime' => 8000])
            ->assertOk()->assertJsonPath('titre', 'Salle de bain complète');

        $this->agirEn($commercial)->putJson("/api/opportunites/{$affaire->id}", ['prochaine_action' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('prochaine_action');
        $this->agirEn($commercial)->putJson("/api/opportunites/{$affaire->id}", ['prochaine_action_le' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('prochaine_action');

        $this->agirEn($commercial)->putJson("/api/opportunites/{$affaire->id}", ['prochaine_action' => 'Envoyer le devis', 'prochaine_action_le' => now()->addDay()->toDateString()])
            ->assertOk()->assertJsonPath('prochaine_action', 'Envoyer le devis');
    }

    public function test_le_client_et_l_etape_ne_changent_pas_par_la_modification(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $client = $this->nouveauClient();
        $autreClient = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Martin']));
        $affaire = $this->affaire($client, $commercial);

        $this->agirEn($commercial)->putJson("/api/opportunites/{$affaire->id}", [
            'client_id' => $autreClient->id, 'etape_id' => $this->etape('devis_envoye')->id, 'titre' => 'Nouveau titre',
        ])->assertOk();

        $apres = $this->chez(fn () => Opportunite::query()->findOrFail($affaire->id));
        $this->assertSame($client->id, $apres->client_id);
        $this->assertSame($this->etape('nouveau')->id, $apres->etape_id);
        $this->assertSame('Nouveau titre', $apres->titre);
    }

    public function test_changement_d_etape_a_la_main_avec_historique(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('visite_planifiee')->id])
            ->assertOk()->assertJsonPath('etape.code', 'visite_planifiee')->assertJsonPath('probabilite', 25);

        $this->chez(function () use ($affaire, $commercial): void {
            $ligne = OpportuniteEtape::query()->where('opportunite_id', $affaire->id)->firstOrFail();
            $this->assertSame('human', $ligne->triggered_by);
            $this->assertFalse($ligne->retour_arriere);
            $this->assertSame($commercial->id, $ligne->auteur_id);
            $this->assertSame(1, \Spatie\Activitylog\Models\Activity::query()->where('event', 'etape')->count());
        });
    }

    public function test_un_retour_en_arriere_est_permis_et_signale(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial, ['etape_id' => $this->etape('visite_faite')->id, 'probabilite' => 35]);

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('a_qualifier')->id])
            ->assertOk()->assertJsonPath('probabilite', 15);

        $this->chez(function () use ($affaire): void {
            $this->assertTrue(OpportuniteEtape::query()->where('opportunite_id', $affaire->id)->firstOrFail()->retour_arriere);
        });
    }

    public function test_les_etapes_pilotees_par_le_devis_se_refusent_a_la_main(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);

        foreach (['devis_envoye', 'negociation', 'gagne'] as $code) {
            $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape($code)->id])
                ->assertUnprocessable()->assertJsonValidationErrors('etape_id');
        }

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('nouveau')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('etape_id');

        $this->chez(fn () => $this->assertSame(0, OpportuniteEtape::query()->count()));
    }

    public function test_une_affaire_gagnee_ne_se_deplace_plus_a_la_main(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $gagne = $this->etape('gagne');
        $affaire = $this->affaire($this->nouveauClient(), $commercial, ['etape_id' => $gagne->id]);

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('devis_en_cours')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('etape_id');
    }

    public function test_perdu_exige_un_motif_de_perte(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);
        $perdu = $this->etape('perdu');
        $motifPerte = $this->motif('perte');
        $motifEcart = $this->motif('ecart_demande');

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $perdu->id])
            ->assertUnprocessable()->assertJsonValidationErrors('motif_perte_id');
        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $perdu->id, 'motif_perte_id' => $motifEcart->id])
            ->assertUnprocessable()->assertJsonValidationErrors('motif_perte_id');

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", [
            'etape_id' => $perdu->id, 'motif_perte_id' => $motifPerte->id, 'concurrent' => 'Batipro',
        ])->assertOk()
            ->assertJsonPath('etape.code', 'perdu')
            ->assertJsonPath('probabilite', 0)
            ->assertJsonPath('motif_perte_id', $motifPerte->id)
            ->assertJsonPath('concurrent', 'Batipro')
            ->assertJsonPath('prochaine_action', null);

        $this->chez(fn () => $this->assertNotNull(Opportunite::query()->findOrFail($affaire->id)->perdue_le));
    }

    public function test_sans_suite_exige_un_motif_d_ecart_de_demande(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);
        $sansSuite = $this->etape('sans_suite');

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $sansSuite->id, 'motif_perte_id' => $this->motif('perte')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('motif_perte_id');

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $sansSuite->id, 'motif_perte_id' => $this->motif('ecart_demande')->id])
            ->assertOk()->assertJsonPath('etape.code', 'sans_suite')->assertJsonPath('probabilite', 0);
    }

    public function test_un_motif_inactif_est_refuse(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);
        $motif = $this->motif('perte');
        $this->chez(fn () => $motif->update(['actif' => false]));

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('perdu')->id, 'motif_perte_id' => $motif->id])
            ->assertUnprocessable()->assertJsonValidationErrors('motif_perte_id');
    }

    public function test_une_affaire_cloturee_se_rouvre_avec_une_prochaine_action(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);
        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('perdu')->id, 'motif_perte_id' => $this->motif('perte')->id])->assertOk();

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('a_qualifier')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('prochaine_action');

        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", [
            'etape_id' => $this->etape('a_qualifier')->id,
            'prochaine_action' => 'Relancer le client',
            'prochaine_action_le' => now()->addDays(2)->toDateString(),
        ])->assertOk()
            ->assertJsonPath('etape.code', 'a_qualifier')
            ->assertJsonPath('probabilite', 15)
            ->assertJsonPath('motif_perte_id', null)
            ->assertJsonPath('prochaine_action', 'Relancer le client');

        $this->chez(function () use ($affaire): void {
            $this->assertNull(Opportunite::query()->findOrFail($affaire->id)->perdue_le);
            $derniere = OpportuniteEtape::query()->where('opportunite_id', $affaire->id)->orderByDesc('id')->firstOrFail();
            $this->assertTrue($derniere->retour_arriere);
        });
    }

    public function test_une_affaire_cloturee_ne_garde_que_ses_notes_modifiables(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);
        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('perdu')->id, 'motif_perte_id' => $this->motif('perte')->id])->assertOk();

        $this->agirEn($commercial)->putJson("/api/opportunites/{$affaire->id}", ['titre' => 'Autre titre', 'notes' => 'Client parti chez un concurrent'])
            ->assertOk()->assertJsonPath('titre', 'Salle de bain')->assertJsonPath('notes', 'Client parti chez un concurrent');
    }

    public function test_la_fiche_donne_l_historique_et_les_liens(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);
        $this->agirEn($commercial)->postJson("/api/opportunites/{$affaire->id}/etape", ['etape_id' => $this->etape('a_qualifier')->id])->assertOk();

        $this->agirEn($commercial)->getJson("/api/opportunites/{$affaire->id}")->assertOk()
            ->assertJsonPath('client.nom', 'Dupont')
            ->assertJsonPath('etape.code', 'a_qualifier')
            ->assertJsonCount(1, 'historique')
            ->assertJsonPath('historique.0.etape_vers.code', 'a_qualifier');
    }

    public function test_les_filtres_de_la_liste(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $client = $this->nouveauClient();
        $this->affaire($client, $direction, ['titre' => 'Toiture']);
        $this->affaire($client, $direction, ['titre' => 'Cuisine', 'prochaine_action_le' => now()->subDays(2)->toDateString()]);
        $perdue = $this->affaire($client, $direction, ['titre' => 'Jardin']);
        $this->chez(fn () => $perdue->update(['etape_id' => EtapePipeline::query()->where('code', 'perdu')->value('id')]));

        $this->agirEn($direction)->getJson('/api/opportunites?statut=ouvertes')->assertJsonCount(2, 'data');
        $this->agirEn($direction)->getJson('/api/opportunites?statut=cloturees')->assertJsonCount(1, 'data')->assertJsonPath('data.0.titre', 'Jardin');
        $this->agirEn($direction)->getJson('/api/opportunites?en_retard=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.titre', 'Cuisine');
        $this->agirEn($direction)->getJson('/api/opportunites?recherche=toit')->assertJsonCount(1, 'data');
        $this->agirEn($direction)->getJson('/api/opportunites?recherche=dupont')->assertJsonCount(3, 'data');
        $this->agirEn($direction)->getJson('/api/opportunites?etape_id=' . $this->etape('perdu')->id)->assertJsonCount(1, 'data');
    }

    public function test_la_suppression_archive_l_affaire(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->affaire($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->deleteJson("/api/opportunites/{$affaire->id}")->assertNoContent();

        $this->agirEn($commercial)->getJson('/api/opportunites')->assertJsonCount(0, 'data');
        $this->chez(fn () => $this->assertNotNull(Opportunite::withTrashed()->findOrFail($affaire->id)->deleted_at));
    }
}
