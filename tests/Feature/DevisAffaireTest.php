<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\EtapePipeline;
use App\Models\Opportunite;
use App\Models\OpportuniteEtape;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D2 bis : tout devis créé a une affaire, et la création
 * d'un devis fait passer l'affaire à « Devis en cours ».
 */
class DevisAffaireTest extends TestCase
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

    private function nouvelleAffaire(Client $client, User $commercial, ?string $codeEtape = null): Opportunite
    {
        $affaire = $this->chez(fn () => Opportunite::create([
            'client_id' => $client->id,
            'titre' => 'Salle de bain',
            'commercial_id' => $commercial->id,
            'prochaine_action' => 'Appeler le client',
            'prochaine_action_le' => now()->addDays(10)->toDateString(),
        ]));

        if ($codeEtape !== null) {
            $etape = $this->etape($codeEtape);
            $this->chez(fn () => $affaire->forceFill([
                'etape_id' => $etape->id,
                'probabilite' => $etape->probabilite_defaut,
                'perdue_le' => in_array($codeEtape, ['perdu', 'sans_suite'], true) ? now() : null,
                'gagnee_le' => $codeEtape === 'gagne' ? now() : null,
            ])->save());
        }

        return $affaire;
    }

    private function relue(int $id): Opportunite
    {
        return $this->chez(fn () => Opportunite::query()->with('etape')->findOrFail($id));
    }

    private function historique(int $affaireId): \Illuminate\Support\Collection
    {
        return $this->chez(fn () => OpportuniteEtape::query()->where('opportunite_id', $affaireId)->orderBy('id')->get());
    }

    public function test_un_devis_cree_sans_affaire_cree_son_affaire_a_devis_en_cours(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $reponse = $this->agirEn($commercial)->postJson('/api/devis', [
            'client_id' => $client->id, 'titre' => 'Rénovation cuisine',
        ])->assertCreated();

        $numero = $reponse->json('numero');
        $affaireId = $reponse->json('opportunite_id');
        $this->assertNotNull($affaireId);
        $reponse->assertJsonPath('opportunite.titre', 'Rénovation cuisine');

        $affaire = $this->relue($affaireId);
        $this->assertSame($client->id, $affaire->client_id);
        $this->assertSame('Rénovation cuisine', $affaire->titre);
        $this->assertSame($commercial->id, $affaire->commercial_id);
        $this->assertSame('devis_en_cours', $affaire->etape->code);
        $this->assertEquals(45, $affaire->probabilite);
        $this->assertSame('Finaliser et envoyer le devis ' . $numero, $affaire->prochaine_action);
        $this->assertSame(now()->addDays(3)->toDateString(), $affaire->prochaine_action_le->toDateString());

        $lignes = $this->historique($affaireId);
        $this->assertCount(1, $lignes);
        $this->assertNull($lignes[0]->etape_depuis_id);
        $this->assertSame($this->etape('devis_en_cours')->id, $lignes[0]->etape_vers_id);
        $this->assertSame($reponse->json('id'), $lignes[0]->devis_id);
        $this->assertSame($commercial->id, $lignes[0]->auteur_id);

        $this->assertTrue($this->chez(fn () => Activity::query()
            ->where('subject_type', (new Opportunite())->getMorphClass())
            ->where('subject_id', $affaireId)
            ->where('event', 'created')
            ->exists()));
    }

    public function test_deux_devis_sans_affaire_donnent_deux_affaires(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $a = $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Cuisine'])->assertCreated();
        $b = $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Terrasse'])->assertCreated();

        $this->assertNotSame($a->json('opportunite_id'), $b->json('opportunite_id'));
        $this->assertSame(2, $this->chez(fn () => Opportunite::query()->count()));
    }

    public function test_un_devis_cree_sur_une_affaire_la_fait_passer_a_devis_en_cours(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $affaire = $this->nouvelleAffaire($client, $commercial);

        $reponse = $this->agirEn($commercial)->postJson('/api/devis', [
            'client_id' => $client->id, 'titre' => 'Salle de bain', 'opportunite_id' => $affaire->id,
        ])->assertCreated()->assertJsonPath('opportunite_id', $affaire->id);

        $relue = $this->relue($affaire->id);
        $this->assertSame('devis_en_cours', $relue->etape->code);
        $this->assertEquals(45, $relue->probabilite);
        $this->assertSame('Appeler le client', $relue->prochaine_action);
        $this->assertSame(1, $this->chez(fn () => Opportunite::query()->count()));

        $lignes = $this->historique($affaire->id);
        $this->assertCount(1, $lignes);
        $this->assertSame($this->etape('nouveau')->id, $lignes[0]->etape_depuis_id);
        $this->assertSame($reponse->json('id'), $lignes[0]->devis_id);
        $this->assertFalse((bool) $lignes[0]->retour_arriere);
    }

    public function test_une_affaire_plus_avancee_ou_gagnee_ne_bouge_pas(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        foreach (['devis_en_cours', 'devis_envoye', 'negociation', 'gagne'] as $code) {
            $affaire = $this->nouvelleAffaire($client, $commercial, $code);

            $this->agirEn($commercial)->postJson('/api/devis', [
                'client_id' => $client->id, 'titre' => 'Variante', 'opportunite_id' => $affaire->id,
            ])->assertCreated();

            $this->assertSame($code, $this->relue($affaire->id)->etape->code, $code);
            $this->assertCount(0, $this->historique($affaire->id), $code);
        }
    }

    public function test_une_affaire_cloturee_est_rouverte_a_devis_en_cours(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        foreach (['perdu', 'sans_suite'] as $code) {
            $affaire = $this->nouvelleAffaire($client, $commercial, $code);

            $reponse = $this->agirEn($commercial)->postJson('/api/devis', [
                'client_id' => $client->id, 'titre' => 'Nouvelle offre', 'opportunite_id' => $affaire->id,
            ])->assertCreated();

            $relue = $this->relue($affaire->id);
            $this->assertSame('devis_en_cours', $relue->etape->code, $code);
            $this->assertEquals(45, $relue->probabilite);
            $this->assertNull($relue->perdue_le);
            $this->assertNull($relue->motif_perte_id);
            $this->assertSame('Finaliser le devis ' . $reponse->json('numero'), $relue->prochaine_action);
            $this->assertSame(now()->addDays(3)->toDateString(), $relue->prochaine_action_le->toDateString());

            $lignes = $this->historique($affaire->id);
            $this->assertCount(1, $lignes);
            $this->assertTrue((bool) $lignes[0]->retour_arriere);
        }
    }

    public function test_l_envoi_d_un_devis_cree_sans_affaire_fait_avancer_l_affaire_creee(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $reponse = $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Cuisine'])->assertCreated();
        $devisId = $reponse->json('id');
        $affaireId = $reponse->json('opportunite_id');

        $this->agirEn($commercial)->postJson("/api/devis/{$devisId}/lignes", [
            'libelle' => 'Pose de carrelage', 'quantite' => 2, 'prix_unitaire_ht' => 100,
        ])->assertSuccessful();
        $this->agirEn($commercial)->postJson("/api/devis/{$devisId}/envoyer")->assertOk();

        $this->assertSame('devis_envoye', $this->relue($affaireId)->etape->code);
        $this->assertCount(2, $this->historique($affaireId));
    }

    public function test_rattacher_un_devis_a_une_autre_affaire_la_fait_avancer(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $autre = $this->nouvelleAffaire($client, $commercial);

        $reponse = $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Cuisine'])->assertCreated();
        $devisId = $reponse->json('id');

        $this->agirEn($commercial)->putJson("/api/devis/{$devisId}", ['opportunite_id' => $autre->id])
            ->assertOk()->assertJsonPath('opportunite_id', $autre->id);

        $this->assertSame('devis_en_cours', $this->relue($autre->id)->etape->code);
    }

    public function test_on_ne_retire_pas_l_affaire_d_un_devis(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $reponse = $this->agirEn($commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Cuisine'])->assertCreated();
        $devisId = $reponse->json('id');

        $this->agirEn($commercial)->putJson("/api/devis/{$devisId}", ['opportunite_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('opportunite_id');
        $this->agirEn($commercial)->putJson("/api/devis/{$devisId}", ['titre' => 'Cuisine et cellier'])->assertOk();

        $this->assertSame($reponse->json('opportunite_id'), $this->chez(fn () => Devis::query()->findOrFail($devisId)->opportunite_id));
    }

    public function test_un_devis_ancien_sans_affaire_reste_modifiable(): void
    {
        $client = $this->nouveauClient();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $devis = $this->chez(fn () => Devis::create([
            'client_id' => $client->id, 'titre' => 'Ancien devis', 'auteur_id' => $commercial->id, 'portee' => 'entreprise',
        ]));

        $this->agirEn($commercial)->putJson("/api/devis/{$devis->id}", ['titre' => 'Ancien devis repris'])
            ->assertOk()->assertJsonPath('opportunite_id', null);
        $this->assertSame(0, $this->chez(fn () => Opportunite::query()->count()));
    }
}
