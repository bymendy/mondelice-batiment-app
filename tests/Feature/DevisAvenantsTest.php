<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\Opportunite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D5 : avenants d'un devis accepté, numérotation, montant
 * cumulé du marché.
 */
class DevisAvenantsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
    }

    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    /**
     * Devis créé par l'API (numéroté, avec son affaire) et une ligne.
     *
     * @return array{id: int, numero: string}
     */
    private function nouveauDevis(float $prix = 1000, float $revient = 600): array
    {
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));
        $reponse = $this->agirEn($this->commercial)->postJson('/api/devis', [
            'client_id' => $client->id, 'titre' => 'Rénovation de la salle de bain',
        ])->assertCreated();
        $this->ligne($reponse->json('id'), 1, $prix, $revient);

        return ['id' => $reponse->json('id'), 'numero' => $reponse->json('numero')];
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function ligne(int $devisId, float $quantite, float $prix, float $revient)
    {
        return $this->agirEn($this->commercial)->postJson("/api/devis/{$devisId}/lignes", [
            'libelle' => 'Travaux', 'quantite' => $quantite, 'prix_unitaire_ht' => $prix, 'prix_revient_unitaire' => $revient,
        ]);
    }

    /**
     * Pose un statut sans passer par l'envoi et l'acceptation (testés ailleurs).
     */
    private function passerA(int $devisId, string $statut): void
    {
        $this->chez(fn () => Devis::query()->findOrFail($devisId)->forceFill(['statut' => $statut])->save());
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function creerAvenant(int $devisId, array $corps = [], ?User $auteur = null)
    {
        return $this->agirEn($auteur ?? $this->commercial)->postJson("/api/devis/{$devisId}/avenants", $corps);
    }

    /**
     * @param \Illuminate\Testing\TestResponse $reponse
     */
    private function montant($reponse, string $chemin, float $attendu): void
    {
        $this->assertEqualsWithDelta($attendu, (float) $reponse->json($chemin), 0.001, $chemin);
    }

    public function test_un_devis_accepte_recoit_des_avenants_numerotes(): void
    {
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'accepte');
        $origine = $this->chez(fn () => Devis::query()->findOrFail($devis['id']));

        $premier = $this->creerAvenant($devis['id'])->assertCreated()
            ->assertJsonPath('numero', $devis['numero'] . '-A1')
            ->assertJsonPath('devis_parent_id', $devis['id'])
            ->assertJsonPath('rang_avenant', 1)
            ->assertJsonPath('statut', 'en_preparation')
            ->assertJsonPath('titre', 'Avenant n° 1 au devis ' . $devis['numero'])
            ->assertJsonPath('client_id', $origine->client_id)
            ->assertJsonPath('opportunite_id', $origine->opportunite_id)
            ->assertJsonPath('portee', $origine->portee)
            ->assertJsonPath('auteur_id', $this->commercial->id)
            ->assertJsonPath('devis_origine.numero', $devis['numero']);
        $this->montant($premier, 'total_ht', 0);

        $this->creerAvenant($devis['id'], ['titre' => 'Ajout d\'un meuble vasque'])->assertCreated()
            ->assertJsonPath('numero', $devis['numero'] . '-A2')
            ->assertJsonPath('rang_avenant', 2)
            ->assertJsonPath('titre', 'Ajout d\'un meuble vasque');

        // L'avenant ne crée pas d'affaire, et il est tracé.
        $this->assertSame(1, $this->chez(fn () => Opportunite::query()->count()));
        $this->assertSame(2, $this->chez(fn () => Activity::query()
            ->where('event', 'created')
            ->where('properties->devis_origine', $devis['numero'])
            ->count()));
    }

    public function test_seul_un_devis_d_origine_accepte_recoit_un_avenant(): void
    {
        $devis = $this->nouveauDevis();
        $this->creerAvenant($devis['id'])->assertUnprocessable()->assertJsonValidationErrors('devis');

        $this->passerA($devis['id'], 'envoye');
        $this->creerAvenant($devis['id'])->assertUnprocessable()->assertJsonValidationErrors('devis');

        $this->passerA($devis['id'], 'accepte');
        $avenantId = $this->creerAvenant($devis['id'])->assertCreated()->json('id');

        // Pas d'avenant sur un avenant, même accepté.
        $this->passerA($avenantId, 'accepte');
        $this->creerAvenant($avenantId)->assertUnprocessable()->assertJsonValidationErrors('devis');
    }

    public function test_les_droits_de_creation_d_un_avenant(): void
    {
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'accepte');

        foreach (['Conducteur travaux', 'Administratif'] as $role) {
            $this->creerAvenant($devis['id'], [], $this->creerUtilisateur($this->entreprise, $role))->assertForbidden();
        }
        $this->creerAvenant($devis['id'], [], $this->direction)->assertCreated();

        $autre = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');
        $this->creerAvenant($devis['id'], [], $autre)->assertNotFound();
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->postJson('/api/devis/1/avenants')->assertUnauthorized();
    }

    public function test_un_rang_n_est_jamais_reutilise_et_les_devis_gardent_leur_serie(): void
    {
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'accepte');

        $premier = $this->creerAvenant($devis['id'])->assertCreated()->json('id');
        $this->agirEn($this->commercial)->deleteJson("/api/devis/{$premier}")->assertSuccessful();
        $this->creerAvenant($devis['id'])->assertCreated()->assertJsonPath('numero', $devis['numero'] . '-A2');

        // Le prochain devis d'origine suit la série des devis, sans compter les avenants.
        $suivant = $this->nouveauDevis();
        $this->assertSame(
            (int) substr($devis['numero'], -4) + 1,
            (int) substr($suivant['numero'], -4)
        );
        $this->assertStringNotContainsString('-A', $suivant['numero']);
    }

    public function test_la_fiche_donne_les_avenants_et_le_montant_cumule_du_marche(): void
    {
        $devis = $this->nouveauDevis(1000, 600);
        $this->passerA($devis['id'], 'accepte');

        $accepte = $this->creerAvenant($devis['id'])->assertCreated()->json('id');
        $this->ligne($accepte, 1, 200, 120)->assertCreated();
        $this->passerA($accepte, 'accepte');

        $enCours = $this->creerAvenant($devis['id'])->assertCreated()->json('id');
        $this->ligne($enCours, -1, 50, 30)->assertCreated();      // travaux en moins

        $refuse = $this->creerAvenant($devis['id'])->assertCreated()->json('id');
        $this->ligne($refuse, 1, 300, 200)->assertCreated();
        $this->passerA($refuse, 'refuse');

        $ttc = fn (int $id): float => (float) $this->chez(fn () => Devis::query()->findOrFail($id)->total_ttc);

        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis['id']}")->assertOk()
            ->assertJsonCount(3, 'avenants')
            ->assertJsonPath('avenants.0.numero', $devis['numero'] . '-A1')
            ->assertJsonPath('avenants.0.statut', 'accepte')
            ->assertJsonPath('avenants.1.statut', 'en_preparation')
            ->assertJsonPath('avenants.2.statut', 'refuse')
            ->assertJsonPath('marche.devis_accepte', true)
            ->assertJsonPath('marche.avenants_acceptes.nombre', 1)
            ->assertJsonPath('marche.avenants_en_cours.nombre', 1)
            ->assertJsonMissingPath('devis_origine');
        $this->montant($fiche, 'marche.devis_ht', 1000);
        $this->montant($fiche, 'marche.avenants_acceptes.ht', 200);
        $this->montant($fiche, 'marche.avenants_en_cours.ht', -50);
        $this->montant($fiche, 'marche.cumul_ht', 1200);
        $this->montant($fiche, 'marche.cumul_ttc', $ttc($devis['id']) + $ttc($accepte));
        $this->montant($fiche, 'avenants.1.total_ht', -50);

        $this->agirEn($this->commercial)->getJson("/api/devis/{$accepte}")->assertOk()
            ->assertJsonPath('devis_origine.id', $devis['id'])
            ->assertJsonPath('devis_origine.numero', $devis['numero'])
            ->assertJsonMissingPath('marche')
            ->assertJsonMissingPath('avenants');
    }

    public function test_un_devis_non_accepte_n_engage_aucun_montant(): void
    {
        $devis = $this->nouveauDevis(1000, 600);

        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis['id']}")->assertOk()
            ->assertJsonCount(0, 'avenants')
            ->assertJsonPath('marche.devis_accepte', false);
        $this->montant($fiche, 'marche.cumul_ht', 0);
    }

    public function test_un_avenant_suit_le_cycle_d_un_devis_sans_deplacer_l_affaire(): void
    {
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'accepte');
        $affaireId = $this->chez(fn () => Devis::query()->findOrFail($devis['id'])->opportunite_id);
        $etapeAvant = $this->chez(fn () => Opportunite::query()->findOrFail($affaireId)->etape_id);

        $avenant = $this->creerAvenant($devis['id'])->assertCreated()->json('id');
        $this->agirEn($this->commercial)->postJson("/api/devis/{$avenant}/envoyer")->assertUnprocessable();   // aucune ligne
        $this->ligne($avenant, 1, 200, 120)->assertCreated();
        $this->agirEn($this->commercial)->postJson("/api/devis/{$avenant}/envoyer")->assertOk()->assertJsonPath('statut', 'envoye');

        $this->assertSame($etapeAvant, $this->chez(fn () => Opportunite::query()->findOrFail($affaireId)->etape_id));
        $this->ligne($avenant, 1, 10, 5)->assertUnprocessable();   // un avenant envoyé est figé
    }

    public function test_les_seuils_s_appliquent_a_l_avenant_seul(): void
    {
        $devis = $this->nouveauDevis(1000, 600);   // 40 % de marge
        $this->passerA($devis['id'], 'accepte');
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_globale_min', ['valeur' => 20])->assertOk();

        $avenant = $this->creerAvenant($devis['id'])->assertCreated()->json('id');
        $this->ligne($avenant, 1, 100, 95)->assertUnprocessable()->assertJsonPath('motif', 'marge_sous_le_seuil');
        $this->ligne($avenant, 1, 100, 70)->assertCreated();
    }
}
