<?php

namespace Tests\Feature;

use App\Models\AlerteSeuil;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AlerteSeuilDevis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D4b (2e partie) : alertes de seuil regroupées, envoyées à
 * l'écran et par e-mail à la Direction et à l'Administratif.
 */
class DevisAlertesSeuilsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private User $administratif;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');
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

    /**
     * @return \Illuminate\Support\Collection<int, AlerteSeuil>
     */
    private function alertes()
    {
        return $this->chez(fn () => AlerteSeuil::query()->orderBy('id')->get());
    }

    /**
     * Devis à 30 % de marge, seuil à 20 % : toute ligne à faible marge est refusée.
     */
    private function devisSousControle(): Devis
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, 100, 70)->assertCreated();

        return $devis;
    }

    public function test_le_premier_refus_cree_une_alerte_et_previent_direction_et_administratif(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();

        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();

        $alertes = $this->alertes();
        $this->assertCount(1, $alertes);
        $this->assertSame($devis->id, $alertes[0]->devis_id);
        $this->assertSame($this->commercial->id, $alertes[0]->user_id);
        $this->assertSame('marge_globale_min', $alertes[0]->type_seuil);
        $this->assertSame('ecriture', $alertes[0]->moment);
        $this->assertSame(1, $alertes[0]->nombre);
        $this->assertEqualsWithDelta(20, (float) $alertes[0]->seuil, 0.001);
        $this->assertEqualsWithDelta(17.5, (float) $alertes[0]->derniere_valeur, 0.001);
        $this->assertEqualsWithDelta(17.5, (float) $alertes[0]->pire_valeur, 0.001);

        Notification::assertSentTo([$this->direction, $this->administratif], AlerteSeuilDevis::class);
        Notification::assertNotSentTo($this->commercial, AlerteSeuilDevis::class);
        Notification::assertCount(2);
    }

    public function test_les_refus_suivants_sont_regroupes_sans_nouvel_envoi(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();

        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();   // marge 17,5 %
        $this->ajouterLigne($devis, 100, 99)->assertUnprocessable();   // marge 15,5 %
        $this->ajouterLigne($devis, 100, 92)->assertUnprocessable();   // marge 19 %

        $alertes = $this->alertes();
        $this->assertCount(1, $alertes);
        $this->assertSame(3, $alertes[0]->nombre);
        $this->assertEqualsWithDelta(19, (float) $alertes[0]->derniere_valeur, 0.001);
        $this->assertEqualsWithDelta(15.5, (float) $alertes[0]->pire_valeur, 0.001);
        Notification::assertCount(2);
    }

    public function test_apres_le_delai_un_refus_ouvre_une_nouvelle_alerte(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();

        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();
        $this->travel(25)->hours();
        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();
        $this->assertCount(2, $this->alertes());
        Notification::assertCount(4);

        // La Direction porte le délai à 48 heures : le refus suivant est regroupé.
        $this->agirEn($this->direction)->putJson('/api/parametres/alertes_seuil_delai_heures', ['valeur' => 48])->assertOk();
        $this->travel(25)->hours();
        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();

        $alertes = $this->alertes();
        $this->assertCount(2, $alertes);
        $this->assertSame(1, $alertes[0]->nombre);
        $this->assertSame(2, $alertes[1]->nombre);
        Notification::assertCount(4);
    }

    public function test_chaque_type_de_seuil_a_son_alerte(): void
    {
        Notification::fake();
        $this->seuil('marge_globale_min', 20);
        $this->seuil('remise_max', 10);
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, 100, 50)->assertCreated();

        $remise = fn (int $valeur) => $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/remises", [
            'libelle' => 'Geste commercial', 'type' => 'pourcentage', 'valeur' => $valeur,
        ]);
        $remise(15)->assertUnprocessable()->assertJsonPath('motif', 'remise_au_dessus_du_maximum');
        $remise(12)->assertUnprocessable()->assertJsonPath('motif', 'remise_au_dessus_du_maximum');
        $this->ajouterLigne($devis, 100, 120)->assertUnprocessable()->assertJsonPath('motif', 'marge_sous_le_seuil');

        $alertes = $this->alertes();
        $this->assertCount(2, $alertes);
        $this->assertSame('remise_max', $alertes[0]->type_seuil);
        $this->assertSame(2, $alertes[0]->nombre);
        $this->assertEqualsWithDelta(12, (float) $alertes[0]->derniere_valeur, 0.001);
        $this->assertEqualsWithDelta(15, (float) $alertes[0]->pire_valeur, 0.001);
        $this->assertSame('marge_globale_min', $alertes[1]->type_seuil);
        Notification::assertCount(4);
    }

    public function test_la_direction_sous_le_seuil_ne_declenche_aucune_alerte(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();

        $this->ajouterLigne($devis, 100, 95, $this->direction)->assertCreated();

        $this->assertCount(0, $this->alertes());
        Notification::assertNothingSent();
    }

    public function test_un_envoi_refuse_alerte_mais_pas_une_marge_incomplete(): void
    {
        Notification::fake();
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, 100, 95)->assertCreated();
        $this->seuil('marge_globale_min', 20);

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertUnprocessable()->assertJsonPath('motif', 'marge_sous_le_seuil');
        $alertes = $this->alertes();
        $this->assertCount(1, $alertes);
        $this->assertSame('envoi', $alertes[0]->moment);

        $incomplet = $this->nouveauDevis();
        $this->ajouterLigne($incomplet, 100, null)->assertCreated();
        $this->agirEn($this->commercial)->postJson("/api/devis/{$incomplet->id}/envoyer")
            ->assertUnprocessable()->assertJsonPath('motif', 'marge_incomplete');
        $this->assertCount(1, $this->alertes());
    }

    public function test_la_liste_des_alertes_est_reservee_a_la_direction_et_a_l_administratif(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();
        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();

        foreach ([$this->direction, $this->administratif] as $lecteur) {
            $this->agirEn($lecteur)->getJson('/api/alertes-seuils')->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.devis.id', $devis->id)
                ->assertJsonPath('data.0.auteur.name', $this->commercial->name)
                ->assertJsonPath('data.0.type_seuil', 'marge_globale_min')
                ->assertJsonPath('data.0.nombre', 1);
        }
        $this->agirEn($this->direction)->getJson('/api/alertes-seuils?devis_id=' . ($devis->id + 1000))->assertOk()->assertJsonCount(0, 'data');
        $this->agirEn($this->commercial)->getJson('/api/alertes-seuils')->assertForbidden();

        $autre = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');
        $this->agirEn($autre)->getJson('/api/alertes-seuils')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_l_alerte_arrive_a_l_ecran_et_par_e_mail(): void
    {
        $devis = $this->devisSousControle();
        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();

        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertCount(2, Mail::getSymfonyTransport()->messages());

        $liste = $this->agirEn($this->direction)->getJson('/api/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('non_lues', 1)
            ->assertJsonPath('data.0.type', 'AlerteSeuilDevis')
            ->assertJsonPath('data.0.data.devis_id', $devis->id)
            ->assertJsonPath('data.0.data.auteur_nom', $this->commercial->name)
            ->assertJsonPath('data.0.data.type_seuil', 'marge_globale_min');
        $id = $liste->json('data.0.id');

        $this->agirEn($this->commercial)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->agirEn($this->commercial)->postJson("/api/notifications/{$id}/lue")->assertNotFound();

        $this->agirEn($this->direction)->postJson("/api/notifications/{$id}/lue")->assertOk();
        $this->agirEn($this->direction)->getJson('/api/notifications')->assertOk()->assertJsonPath('non_lues', 0);
        $this->agirEn($this->administratif)->getJson('/api/notifications')->assertOk()->assertJsonPath('non_lues', 1);
    }

    public function test_seule_la_direction_regle_le_delai_de_regroupement(): void
    {
        $liste = $this->agirEn($this->administratif)->getJson('/api/parametres')->assertOk();
        $delai = collect($liste->json())->firstWhere('cle', 'alertes_seuil_delai_heures');
        $this->assertNotNull($delai);
        $this->assertEquals(24, $delai['valeur']);

        $this->agirEn($this->administratif)->putJson('/api/parametres/alertes_seuil_delai_heures', ['valeur' => 12])->assertForbidden();
        $this->agirEn($this->direction)->putJson('/api/parametres/alertes_seuil_delai_heures', ['valeur' => 0])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson('/api/parametres/alertes_seuil_delai_heures', ['valeur' => 12])
            ->assertOk()->assertJsonPath('valeur', 12);
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->getJson('/api/alertes-seuils')->assertUnauthorized();
        $this->getJson('/api/notifications')->assertUnauthorized();
    }
}
