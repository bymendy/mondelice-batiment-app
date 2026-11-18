<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bibliotheque\ControleSeuilsPrixForce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D4b : seuils du devis (marge globale minimum, remise
 * maximum), contrôle à l'écriture et à l'envoi, indicateur de marge, réglage
 * de la zone orange, journal des refus et du revient saisi à la main.
 */
class DevisSeuilsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
    }

    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    private function seuil(string $type, float|int $valeur): void
    {
        $this->agirEn($this->direction)
            ->putJson("/api/parametres/seuils/{$type}", ['valeur' => $valeur])
            ->assertOk();
    }

    private function nouveauDevis(): Devis
    {
        return $this->chez(function (): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Dupont']);

            return Devis::create([
                'client_id' => $client->id,
                'titre' => 'Salle de bain',
                'auteur_id' => $this->commercial->id,
                'portee' => 'entreprise',
            ]);
        });
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function ajouterLigne(Devis $devis, float $prix, ?float $revient, ?User $auteur = null)
    {
        $corps = ['libelle' => 'Pose de carrelage', 'quantite' => 1, 'prix_unitaire_ht' => $prix];
        if ($revient !== null) {
            $corps['prix_revient_unitaire'] = $revient;
        }

        return $this->agirEn($auteur ?? $this->commercial)->postJson("/api/devis/{$devis->id}/lignes", $corps);
    }

    private function evenements(string $evenement): int
    {
        return $this->chez(fn () => Activity::query()->where('event', $evenement)->count());
    }

    private function totalHt(Devis $devis): float
    {
        return (float) $this->chez(fn () => Devis::query()->findOrFail($devis->id)->total_ht);
    }

    /**
     * @param \Illuminate\Testing\TestResponse $reponse
     */
    private function montant($reponse, string $chemin, float $attendu): void
    {
        $this->assertEqualsWithDelta($attendu, (float) $reponse->json($chemin), 0.001, $chemin);
    }

    public function test_la_direction_regle_les_seuils_du_devis(): void
    {
        $this->seuil('marge_globale_min', 20);
        $this->seuil('remise_max', 10);

        $liste = $this->agirEn($this->direction)->getJson('/api/parametres/seuils')->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.2.type', 'marge_globale_min')
            ->assertJsonPath('data.3.type', 'remise_max');
        $this->montant($liste, 'data.2.valeur', 20);
        $this->montant($liste, 'data.3.valeur', 10);

        $this->agirEn($this->commercial)->putJson('/api/parametres/seuils/marge_globale_min', ['valeur' => 5])->assertForbidden();
    }

    public function test_les_seuils_du_devis_ne_declenchent_pas_le_controle_du_prix_force(): void
    {
        $this->assertFalse((new ControleSeuilsPrixForce(['marge_globale_min' => 20.0, 'remise_max' => 10.0], []))->estActif());
        $this->assertTrue((new ControleSeuilsPrixForce(['marge_mo_min' => 20.0], []))->estActif());
    }

    public function test_sans_seuil_actif_rien_n_est_controle(): void
    {
        $devis = $this->nouveauDevis();

        $this->ajouterLigne($devis, 100, 95)->assertCreated();
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
        $this->assertSame(0, $this->evenements('refus_seuil'));
    }

    public function test_une_ligne_qui_fait_passer_la_marge_sous_le_seuil_est_refusee(): void
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, 100, 70)->assertCreated();

        $reponse = $this->ajouterLigne($devis, 100, 95)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seuil')
            ->assertJsonPath('motif', 'marge_sous_le_seuil');
        $this->montant($reponse, 'seuil', 20);
        $this->montant($reponse, 'valeur', 17.5);

        $this->assertSame(1, $this->chez(fn () => LigneDevis::query()->where('devis_id', $devis->id)->count()));
        $this->assertEqualsWithDelta(100, $this->totalHt($devis), 0.001);
        $this->assertSame(1, $this->evenements('refus_seuil'));
    }

    public function test_une_modification_ou_une_remise_sous_le_seuil_est_refusee(): void
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $ligneId = $this->ajouterLigne($devis, 100, 70)->assertCreated()->json('id');

        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['prix_unitaire_ht' => 80])
            ->assertUnprocessable()->assertJsonPath('motif', 'marge_sous_le_seuil');
        $this->assertEqualsWithDelta(100, (float) $this->chez(fn () => LigneDevis::query()->findOrFail($ligneId)->prix_unitaire_ht), 0.001);

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/remises", [
            'libelle' => 'Geste commercial', 'type' => 'pourcentage', 'valeur' => 20,
        ])->assertUnprocessable()->assertJsonPath('motif', 'marge_sous_le_seuil');
        $this->assertSame(0, $this->chez(fn () => $devis->deductions()->count()));

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/remises", [
            'libelle' => 'Geste commercial', 'type' => 'pourcentage', 'valeur' => 10,
        ])->assertCreated();
        $this->assertEqualsWithDelta(90, $this->totalHt($devis), 0.001);
        $this->assertSame(2, $this->evenements('refus_seuil'));
    }

    public function test_la_direction_n_est_jamais_bloquee_mais_son_ecriture_est_tracee(): void
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();

        $this->ajouterLigne($devis, 100, 95, $this->direction)->assertCreated();
        $this->assertSame(0, $this->evenements('refus_seuil'));
        $this->assertSame(1, $this->evenements('seuil_direction'));

        $this->agirEn($this->direction)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
        $this->assertSame(2, $this->evenements('seuil_direction'));
    }

    public function test_un_devis_deja_sous_le_seuil_peut_etre_ameliore_mais_pas_aggrave(): void
    {
        $devis = $this->nouveauDevis();
        $ligneId = $this->ajouterLigne($devis, 100, 95)->assertCreated()->json('id');
        $this->seuil('marge_globale_min', 20);

        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['prix_unitaire_ht' => 110])->assertOk();
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['libelle' => 'Pose de faïence'])->assertOk();
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['prix_unitaire_ht' => 105])
            ->assertUnprocessable()->assertJsonPath('motif', 'marge_sous_le_seuil');

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertUnprocessable()->assertJsonPath('motif', 'marge_sous_le_seuil');
        $this->assertSame('en_preparation', (string) $this->chez(fn () => Devis::query()->findOrFail($devis->id)->statut));
    }

    public function test_une_marge_incomplete_passe_en_redaction_mais_bloque_l_envoi(): void
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $ligneId = $this->ajouterLigne($devis, 100, null)->assertCreated()->json('id');

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertUnprocessable()
            ->assertJsonPath('motif', 'marge_incomplete')
            ->assertJsonCount(1, 'lignes_sans_revient')
            ->assertJsonPath('lignes_sans_revient.0.id', $ligneId);

        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['prix_revient_unitaire' => 70])->assertOk();
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk()->assertJsonPath('statut', 'envoye');
    }

    public function test_la_remise_maximum_est_controlee(): void
    {
        $this->seuil('remise_max', 10);
        $devis = $this->nouveauDevis();
        $ligneId = $this->ajouterLigne($devis, 100, 50)->assertCreated()->json('id');

        $reponse = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/remises", [
            'libelle' => 'Geste commercial', 'type' => 'pourcentage', 'valeur' => 15,
        ])->assertUnprocessable()->assertJsonPath('motif', 'remise_au_dessus_du_maximum');
        $this->montant($reponse, 'seuil', 10);
        $this->montant($reponse, 'valeur', 15);

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/remises", [
            'libelle' => 'Geste commercial', 'type' => 'pourcentage', 'valeur' => 10,
        ])->assertCreated();

        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['remise_pct' => 5])
            ->assertUnprocessable()->assertJsonPath('motif', 'remise_au_dessus_du_maximum');

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
    }

    public function test_l_indicateur_de_marge_suit_le_seuil_et_la_zone_orange(): void
    {
        $devis = $this->nouveauDevis();
        $ligneId = $this->ajouterLigne($devis, 100, 70)->assertCreated()->json('id');
        $fiche = fn (User $qui) => $this->agirEn($qui)->getJson("/api/devis/{$devis->id}")->assertOk();

        $fiche($this->commercial)->assertJsonPath('detail_totaux.indicateur_marge.couleur', 'gris')
            ->assertJsonPath('detail_totaux.indicateur_marge.libelle', 'Aucun seuil de marge défini');

        $this->seuil('marge_globale_min', 20);
        $fiche($this->commercial)->assertJsonPath('detail_totaux.indicateur_marge.couleur', 'vert')
            ->assertJsonPath('detail_totaux.indicateur_marge.libelle', 'Marge conforme');

        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['prix_revient_unitaire' => 77])->assertOk();
        $fiche($this->commercial)->assertJsonPath('detail_totaux.indicateur_marge.couleur', 'orange')
            ->assertJsonPath('detail_totaux.indicateur_marge.libelle', 'Marge proche du minimum');

        $this->agirEn($this->direction)->putJson('/api/parametres/marge_zone_orange_points', ['valeur' => 2])->assertOk();
        $fiche($this->commercial)->assertJsonPath('detail_totaux.indicateur_marge.couleur', 'vert');

        $this->agirEn($this->direction)->putJson("/api/lignes-devis/{$ligneId}", ['prix_revient_unitaire' => 85])->assertOk();
        $fiche($this->direction)->assertJsonPath('detail_totaux.indicateur_marge.couleur', 'rouge')
            ->assertJsonPath('detail_totaux.indicateur_marge.libelle', 'Marge sous le minimum');

        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['prix_revient_unitaire' => null])->assertOk();
        $fiche($this->commercial)->assertJsonPath('detail_totaux.indicateur_marge.couleur', 'gris')
            ->assertJsonPath('detail_totaux.indicateur_marge.libelle', 'Marge incomplète');
    }

    public function test_l_indicateur_est_reserve_a_la_direction_et_au_commercial(): void
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, 100, 70)->assertCreated();

        foreach (['Conducteur travaux', 'Administratif'] as $role) {
            $lecteur = $this->creerUtilisateur($this->entreprise, $role);
            $this->agirEn($lecteur)->getJson("/api/devis/{$devis->id}")->assertOk()
                ->assertJsonMissingPath('detail_totaux.indicateur_marge')
                ->assertJsonPath('detail_totaux.marge.revient_complet', true);
        }
    }

    public function test_seule_la_direction_regle_la_zone_orange(): void
    {
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');

        $liste = $this->agirEn($administratif)->getJson('/api/parametres')->assertOk();
        $zone = collect($liste->json())->firstWhere('cle', 'marge_zone_orange_points');
        $this->assertNotNull($zone);
        $this->assertEquals(5, $zone['valeur']);

        $this->agirEn($this->commercial)->putJson('/api/parametres/marge_zone_orange_points', ['valeur' => 3])->assertForbidden();
        $this->agirEn($administratif)->putJson('/api/parametres/marge_zone_orange_points', ['valeur' => 3])->assertForbidden();
        $this->agirEn($administratif)->putJson('/api/parametres/marge_fournitures_defaut', ['valeur' => 30])->assertOk();

        $this->agirEn($this->direction)->putJson('/api/parametres/marge_zone_orange_points', ['valeur' => 60])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson('/api/parametres/marge_zone_orange_points', ['valeur' => 3])
            ->assertOk()->assertJsonPath('valeur', 3);
    }

    public function test_le_revient_saisi_a_la_main_est_journalise(): void
    {
        $devis = $this->nouveauDevis();

        $ligneId = $this->ajouterLigne($devis, 100, 70)->assertCreated()->json('id');
        $this->assertSame(1, $this->evenements('revient_saisi'));

        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['libelle' => 'Pose de faïence'])->assertOk();
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['prix_revient_unitaire' => 70])->assertOk();
        $this->assertSame(1, $this->evenements('revient_saisi'));

        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligneId}", ['prix_revient_unitaire' => 75])->assertOk();
        $this->assertSame(2, $this->evenements('revient_saisi'));

        $this->ajouterLigne($devis, 50, null)->assertCreated();
        $this->assertSame(2, $this->evenements('revient_saisi'));
    }
}
