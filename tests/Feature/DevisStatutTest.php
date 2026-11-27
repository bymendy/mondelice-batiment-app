<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\EtapePipeline;
use App\Models\LigneDevis;
use App\Models\Opportunite;
use App\Models\OpportuniteEtape;
use App\Models\Tenant;
use App\Models\User;
use App\States\Devis\DevisState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D2 : machine a etats, envoi, changements manuels de
 * statut, lien avec les etapes du Pipeline.
 */
class DevisStatutTest extends TestCase
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

    private function nouveauClient(): Client
    {
        return $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));
    }

    private function nouvelleAffaire(Client $client, User $commercial): Opportunite
    {
        return $this->chez(fn () => Opportunite::create([
            'client_id' => $client->id,
            'titre' => 'Salle de bain',
            'commercial_id' => $commercial->id,
            'prochaine_action' => 'Appeler le client',
            'prochaine_action_le' => now()->addDays(3)->toDateString(),
        ]));
    }

    /**
     * Devis d'entreprise avec une ligne (200 € HT), eventuellement lie a une affaire.
     *
     * @param Client $client
     * @param User $auteur
     * @param Opportunite|null $affaire
     * @param bool $avecLigne
     * @param string $portee
     * @return Devis
     */
    private function devisPret(Client $client, User $auteur, ?Opportunite $affaire = null, bool $avecLigne = true, string $portee = 'entreprise'): Devis
    {
        return $this->chez(function () use ($client, $auteur, $affaire, $avecLigne, $portee) {
            $devis = Devis::create([
                'client_id' => $client->id,
                'titre' => 'Salle de bain',
                'auteur_id' => $auteur->id,
                'portee' => $portee,
                'opportunite_id' => $affaire?->id,
            ]);
            if ($avecLigne) {
                LigneDevis::create([
                    'devis_id' => $devis->id, 'libelle' => 'Pose de carrelage', 'quantite' => 2,
                    'unite' => 'm2', 'prix_unitaire_ht' => 100, 'taux_tva' => 20, 'ordre' => 1,
                ]);
                $devis->recalculerTotaux();
            }

            return $devis;
        });
    }

    private function statutEnBase(Devis $devis): string
    {
        return $this->chez(fn () => (string) Devis::query()->findOrFail($devis->id)->statut);
    }

    private function codeEtape(Opportunite $affaire): string
    {
        return $this->chez(fn () => Opportunite::query()->with('etape')->findOrFail($affaire->id)->etape->code);
    }

    public function test_les_treize_statuts_et_les_passages_autorises(): void
    {
        $this->assertSame(
            ['accepte', 'annule', 'consulte', 'delivre', 'en_attente_derogation', 'en_preparation', 'envoye', 'expire',
                'negociation', 'non_retenu', 'pret_a_envoyer', 'refuse', 'relance_1', 'relance_2'],
            DevisState::getStateMapping()->keys()->sort()->values()->all()
        );

        $config = DevisState::config();
        $this->assertTrue($config->isTransitionAllowed('en_preparation', 'envoye'));
        $this->assertTrue($config->isTransitionAllowed('pret_a_envoyer', 'envoye'));
        $this->assertTrue($config->isTransitionAllowed('envoye', 'negociation'));
        $this->assertTrue($config->isTransitionAllowed('relance_2', 'accepte'));
        $this->assertTrue($config->isTransitionAllowed('negociation', 'refuse'));
        $this->assertFalse($config->isTransitionAllowed('en_preparation', 'accepte'));
        $this->assertFalse($config->isTransitionAllowed('en_attente_derogation', 'envoye'));
        $this->assertFalse($config->isTransitionAllowed('accepte', 'negociation'));
        $this->assertFalse($config->isTransitionAllowed('refuse', 'envoye'));
    }

    public function test_un_commercial_envoie_son_devis(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertOk()
            ->assertJsonPath('statut', 'envoye')
            ->assertJsonPath('statut_libelle', 'Envoyé')
            ->assertJsonPath('macro_etat', 'envoye')
            ->assertJsonPath('total_ht', '200.00')
            ->assertJsonPath('total_ttc', '240.00');

        $relu = $this->chez(fn () => Devis::query()->findOrFail($devis->id));
        $this->assertNotNull($relu->envoye_le);

        $this->agirEn($commercial)->putJson("/api/devis/{$devis->id}", ['titre' => 'Modifie'])->assertUnprocessable();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/lignes", [
            'libelle' => 'X', 'quantite' => 1, 'prix_unitaire_ht' => 10,
        ])->assertUnprocessable();
    }

    public function test_l_envoi_exige_au_moins_une_ligne(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial, null, false);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertUnprocessable()->assertJsonValidationErrors('lignes');
        $this->assertSame('en_preparation', $this->statutEnBase($devis));
    }

    public function test_un_devis_deja_envoye_ne_s_envoie_pas_deux_fois(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
    }

    public function test_les_droits_d_envoi_et_de_changement_de_statut(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $autreCommercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');
        $devis = $this->devisPret($client, $commercial);
        $prive = $this->devisPret($client, $commercial, null, true, 'prive');

        foreach ([$conducteur, $administratif] as $lecteur) {
            $this->agirEn($lecteur)->postJson("/api/devis/{$devis->id}/envoyer")->assertForbidden();
            $this->agirEn($lecteur)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'negociation'])->assertForbidden();
        }

        $this->agirEn($autreCommercial)->postJson("/api/devis/{$prive->id}/envoyer")->assertNotFound();
        $this->agirEn($autreCommercial)->postJson("/api/devis/{$prive->id}/statut", ['statut' => 'negociation'])->assertNotFound();
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->postJson('/api/devis/1/envoyer')->assertUnauthorized();
        $this->postJson('/api/devis/1/statut', ['statut' => 'negociation'])->assertUnauthorized();
    }

    public function test_l_envoi_fait_passer_l_affaire_a_devis_envoye(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->nouvelleAffaire($client, $commercial);
        $devis = $this->devisPret($client, $commercial, $affaire);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();

        $this->assertSame('devis_envoye', $this->codeEtape($affaire));
        $relue = $this->chez(fn () => Opportunite::query()->findOrFail($affaire->id));
        $this->assertEquals(55, $relue->probabilite);

        $ligne = $this->chez(fn () => OpportuniteEtape::query()->where('opportunite_id', $affaire->id)->latest('id')->firstOrFail());
        $this->assertSame('human', $ligne->triggered_by);
        $this->assertFalse((bool) $ligne->retour_arriere);
    }

    public function test_un_devis_n_a_pas_besoin_d_affaire(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", [
            'statut' => 'accepte', 'confirmation' => true, 'motif' => 'Accord oral', 'preuve_acceptation' => 'Devis signé',
        ])->assertOk()->assertJsonPath('statut', 'accepte');
    }

    public function test_une_affaire_en_negociation_ne_recule_pas_quand_un_devis_part(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->nouvelleAffaire($client, $commercial);
        $negociation = $this->etape('negociation');
        $this->chez(fn () => $affaire->forceFill(['etape_id' => $negociation->id])->save());
        $devis = $this->devisPret($client, $commercial, $affaire);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();

        $this->assertSame('negociation', $this->codeEtape($affaire));
    }

    public function test_la_negociation_fait_passer_l_affaire_a_negociation(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->nouvelleAffaire($client, $commercial);
        $devis = $this->devisPret($client, $commercial, $affaire);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'negociation'])
            ->assertUnprocessable()->assertJsonValidationErrors('statut');

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'negociation'])
            ->assertOk()->assertJsonPath('statut', 'negociation')->assertJsonPath('statut_libelle', 'Négociation');

        $this->assertSame('negociation', $this->codeEtape($affaire));
    }

    public function test_accepter_exige_confirmation_motif_et_preuve(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'accepte'])
            ->assertUnprocessable()->assertJsonValidationErrors(['confirmation', 'motif', 'preuve_acceptation']);
        $this->assertSame('envoye', $this->statutEnBase($devis));
    }

    public function test_un_devis_accepte_gagne_l_affaire(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->nouvelleAffaire($client, $commercial);
        $devis = $this->devisPret($client, $commercial, $affaire);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", [
            'statut' => 'accepte', 'confirmation' => true,
            'motif' => 'Le client a signé', 'preuve_acceptation' => 'Devis signé scanné du jour',
        ])->assertOk()->assertJsonPath('statut', 'accepte')->assertJsonPath('macro_etat', 'decide');

        $relu = $this->chez(fn () => Devis::query()->findOrFail($devis->id));
        $this->assertNotNull($relu->accepte_le);
        $this->assertSame('Devis signé scanné du jour', $relu->preuve_acceptation);

        $this->assertSame('gagne', $this->codeEtape($affaire));
        $gagnee = $this->chez(fn () => Opportunite::query()->findOrFail($affaire->id));
        $this->assertEquals(100, $gagnee->probabilite);
        $this->assertNotNull($gagnee->gagnee_le);
        $this->assertNull($gagnee->prochaine_action);
    }

    public function test_un_devis_refuse_ne_deplace_pas_l_affaire(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->nouvelleAffaire($client, $commercial);
        $devis = $this->devisPret($client, $commercial, $affaire);
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'refuse'])
            ->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'refuse', 'motif' => 'Trop cher'])
            ->assertOk()->assertJsonPath('statut', 'refuse');

        $this->assertSame('devis_envoye', $this->codeEtape($affaire));
    }

    public function test_un_devis_peut_etre_annule_avec_un_motif(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'annule'])
            ->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'annule', 'motif' => 'Erreur de client'])
            ->assertOk()->assertJsonPath('statut', 'annule');
    }

    public function test_un_devis_decide_ne_se_rouvre_pas(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'refuse', 'motif' => 'Trop cher'])->assertOk();

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'negociation'])
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->assertSame('refuse', $this->statutEnBase($devis));
    }

    public function test_les_statuts_non_permis_par_cette_route_sont_refuses(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);

        foreach (['envoye', 'delivre', 'expire', 'inconnu', ''] as $statut) {
            $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => $statut])
                ->assertUnprocessable()->assertJsonValidationErrors('statut');
        }
        $this->assertSame('en_preparation', $this->statutEnBase($devis));
    }

    public function test_la_fiche_donne_le_libelle_et_le_macro_etat(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->getJson("/api/devis/{$devis->id}")->assertOk()
            ->assertJsonPath('statut', 'en_preparation')
            ->assertJsonPath('statut_libelle', 'En préparation')
            ->assertJsonPath('macro_etat', 'brouillon');
    }

    public function test_chaque_changement_est_journalise(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->devisPret($this->nouveauClient(), $commercial);

        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
        $this->agirEn($commercial)->postJson("/api/devis/{$devis->id}/statut", ['statut' => 'refuse', 'motif' => 'Trop cher'])->assertOk();

        $entrees = Activity::query()->where('event', 'statut')->where('subject_id', $devis->id)->orderBy('id')->get();
        $this->assertCount(2, $entrees);
        $this->assertSame('envoye', $entrees[0]->properties['vers']);
        $this->assertSame('refuse', $entrees[1]->properties['vers']);
        $this->assertSame('Trop cher', $entrees[1]->properties['motif']);
        $this->assertSame($commercial->id, $entrees[1]->causer_id);
    }
}
