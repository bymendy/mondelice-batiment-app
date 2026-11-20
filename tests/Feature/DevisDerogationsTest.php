<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DerogationDevis;
use App\Models\Devis;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DerogationDevisNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D4c : dérogations aux seuils. Le Commercial demande, la
 * Direction accepte ou refuse, la dérogation acceptée déplace la limite.
 */
class DevisDerogationsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private const MOTIF = 'Client fidèle, chantier de référence pour le secteur.';

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
    private function ajouterLigne(Devis $devis, float $prix, float $revient, ?User $auteur = null)
    {
        return $this->agirEn($auteur ?? $this->commercial)->postJson("/api/devis/{$devis->id}/lignes", [
            'libelle' => 'Pose de carrelage', 'quantite' => 1, 'prix_unitaire_ht' => $prix, 'prix_revient_unitaire' => $revient,
        ]);
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function demander(Devis $devis, float $valeur, string $type = 'marge_globale_min', ?User $auteur = null)
    {
        return $this->agirEn($auteur ?? $this->commercial)->postJson("/api/devis/{$devis->id}/derogations", [
            'type_seuil' => $type, 'valeur_demandee' => $valeur, 'motif' => self::MOTIF,
        ]);
    }

    /**
     * Devis à 30 % de marge, seuil de marge à 20 %.
     */
    private function devisSousControle(): Devis
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, 100, 70)->assertCreated();

        return $devis;
    }

    private function evenements(string $evenement): int
    {
        return $this->chez(fn () => Activity::query()->where('event', $evenement)->count());
    }

    /**
     * @param \Illuminate\Testing\TestResponse $reponse
     */
    private function montant($reponse, string $chemin, float $attendu): void
    {
        $this->assertEqualsWithDelta($attendu, (float) $reponse->json($chemin), 0.001, $chemin);
    }

    public function test_le_commercial_demande_une_derogation_et_la_direction_est_prevenue(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();
        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();

        $reponse = $this->demander($devis, 15)->assertCreated()
            ->assertJsonPath('statut', 'en_attente')
            ->assertJsonPath('type_seuil', 'marge_globale_min')
            ->assertJsonPath('devis_id', $devis->id)
            ->assertJsonPath('demandeur.name', $this->commercial->name)
            ->assertJsonPath('motif', self::MOTIF);
        $this->montant($reponse, 'seuil', 20);
        $this->montant($reponse, 'valeur_demandee', 15);

        Notification::assertSentTo($this->direction, DerogationDevisNotification::class);
        Notification::assertNotSentTo($this->administratif, DerogationDevisNotification::class);
        Notification::assertNotSentTo($this->commercial, DerogationDevisNotification::class);
        $this->assertSame(1, $this->evenements('derogation_demandee'));

        // Tant que la Direction n'a pas répondu, le seuil de l'entreprise s'applique.
        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();
    }

    public function test_les_regles_de_la_demande(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();

        $this->demander($devis, 25)->assertUnprocessable()->assertJsonValidationErrors('valeur_demandee');
        $this->demander($devis, 20)->assertUnprocessable()->assertJsonValidationErrors('valeur_demandee');
        $this->demander($devis, 30, 'remise_max')->assertUnprocessable()->assertJsonValidationErrors('type_seuil');
        $this->demander($devis, 15, 'marge_mo_min')->assertUnprocessable()->assertJsonValidationErrors('type_seuil');
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/derogations", [
            'type_seuil' => 'marge_globale_min', 'valeur_demandee' => 15, 'motif' => 'court',
        ])->assertUnprocessable()->assertJsonValidationErrors('motif');

        $this->demander($devis, 15)->assertCreated();
        $this->demander($devis, 12)->assertUnprocessable()->assertJsonValidationErrors('type_seuil');
        $this->assertSame(1, $this->chez(fn () => DerogationDevis::query()->count()));
    }

    public function test_seul_le_commercial_demande_et_seule_la_direction_decide(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();

        $this->demander($devis, 15, 'marge_globale_min', $this->direction)->assertForbidden();
        $this->demander($devis, 15, 'marge_globale_min', $this->administratif)->assertForbidden();

        $id = $this->demander($devis, 15)->assertCreated()->json('id');
        $this->agirEn($this->commercial)->postJson("/api/derogations-devis/{$id}/accepter")->assertForbidden();
        $this->agirEn($this->administratif)->postJson("/api/derogations-devis/{$id}/refuser", ['motif_decision' => 'Non'])->assertForbidden();
        $this->agirEn($this->commercial)->getJson('/api/derogations-devis')->assertForbidden();
    }

    public function test_une_derogation_acceptee_deplace_la_limite_du_devis(): void
    {
        $devis = $this->devisSousControle();
        $id = $this->demander($devis, 15)->assertCreated()->json('id');
        $this->assertSame(1, DB::table('notifications')->count());

        $reponse = $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/accepter")->assertOk()
            ->assertJsonPath('statut', 'acceptee')
            ->assertJsonPath('decideur.name', $this->direction->name);
        $this->montant($reponse, 'valeur_accordee', 15);
        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertSame(1, $this->evenements('derogation_acceptee'));

        $this->agirEn($this->commercial)->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('non_lues', 1)
            ->assertJsonPath('data.0.type', 'DerogationDevisNotification')
            ->assertJsonPath('data.0.data.evenement', 'acceptee');

        // 17,5 % : au-dessus des 15 % accordés.
        $this->ajouterLigne($devis, 100, 95)->assertCreated();
        // 11,67 % : sous la limite accordée, de nouveau refusé.
        $refus = $this->ajouterLigne($devis, 100, 100)->assertUnprocessable()->assertJsonPath('motif', 'marge_sous_le_seuil');
        $this->montant($refus, 'seuil', 15);

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
    }

    public function test_la_direction_peut_ajuster_la_valeur_accordee(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();
        $id = $this->demander($devis, 12)->assertCreated()->json('id');

        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/accepter", ['valeur_accordee' => 25])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur_accordee');

        $reponse = $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/accepter", [
            'valeur_accordee' => 16, 'motif_decision' => 'Accord jusqu\'à 16 %.',
        ])->assertOk();
        $this->montant($reponse, 'valeur_accordee', 16);
        $this->montant($reponse, 'valeur_demandee', 12);

        $this->ajouterLigne($devis, 100, 95)->assertCreated();              // 17,5 %
        $this->ajouterLigne($devis, 100, 90)->assertUnprocessable();        // 15 % : sous les 16 % accordés

        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/accepter")
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
    }

    public function test_une_derogation_refusee_ne_change_rien(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();
        $id = $this->demander($devis, 15)->assertCreated()->json('id');

        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/refuser")
            ->assertUnprocessable()->assertJsonValidationErrors('motif_decision');
        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/refuser", ['motif_decision' => 'Marge trop basse pour ce client.'])
            ->assertOk()->assertJsonPath('statut', 'refusee')->assertJsonPath('valeur_accordee', null);

        Notification::assertSentTo($this->commercial, DerogationDevisNotification::class);
        $this->assertSame(1, $this->evenements('derogation_refusee'));
        $this->ajouterLigne($devis, 100, 95)->assertUnprocessable();

        // Une nouvelle demande reste possible.
        $this->demander($devis, 17)->assertCreated();
    }

    public function test_le_demandeur_annule_sa_demande_en_attente(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();
        $id = $this->demander($devis, 15)->assertCreated()->json('id');

        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $this->agirEn($collegue)->postJson("/api/derogations-devis/{$id}/annuler")->assertForbidden();

        $this->agirEn($this->commercial)->postJson("/api/derogations-devis/{$id}/annuler")->assertOk()->assertJsonPath('statut', 'annulee');
        $this->agirEn($this->commercial)->postJson("/api/derogations-devis/{$id}/annuler")->assertUnprocessable();
        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/accepter")->assertUnprocessable();

        $this->demander($devis, 14)->assertCreated();
    }

    public function test_une_derogation_sur_la_remise_maximum(): void
    {
        Notification::fake();
        $this->seuil('remise_max', 10);
        $devis = $this->nouveauDevis();
        $this->ajouterLigne($devis, 100, 50)->assertCreated();
        $remise = fn (int $valeur) => $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/remises", [
            'libelle' => 'Geste commercial', 'type' => 'pourcentage', 'valeur' => $valeur,
        ]);

        $remise(15)->assertUnprocessable();
        $this->demander($devis, 5, 'remise_max')->assertUnprocessable()->assertJsonValidationErrors('valeur_demandee');
        $id = $this->demander($devis, 20, 'remise_max')->assertCreated()->json('id');
        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/accepter")->assertOk();

        $remise(15)->assertCreated();
        // 15 % puis 10 % du reste : 23,5 % de remises, au-dessus des 20 % accordés.
        $refus = $remise(10)->assertUnprocessable()->assertJsonPath('motif', 'remise_au_dessus_du_maximum');
        $this->montant($refus, 'seuil', 20);
    }

    public function test_les_listes_de_derogations(): void
    {
        Notification::fake();
        $devis = $this->devisSousControle();
        $id = $this->demander($devis, 15)->assertCreated()->json('id');

        $this->agirEn($this->direction)->getJson('/api/derogations-devis?statut=en_attente')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.devis.id', $devis->id)
            ->assertJsonPath('data.0.demandeur.name', $this->commercial->name);
        $this->agirEn($this->direction)->getJson('/api/derogations-devis?statut=acceptee')->assertOk()->assertJsonCount(0, 'data');

        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}/derogations")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('en_vigueur', []);

        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/accepter")->assertOk();
        $fiche = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}/derogations")->assertOk()
            ->assertJsonPath('data.0.statut', 'acceptee');
        $this->montant($fiche, 'en_vigueur.marge_globale_min', 15);

        // Une autre entreprise ne voit ni ne décide rien.
        $autre = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');
        $this->agirEn($autre)->getJson('/api/derogations-devis')->assertOk()->assertJsonCount(0, 'data');
        $this->agirEn($autre)->postJson("/api/derogations-devis/{$id}/refuser", ['motif_decision' => 'Non'])->assertNotFound();
        $this->agirEn($autre)->getJson("/api/devis/{$devis->id}/derogations")->assertNotFound();
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->getJson('/api/derogations-devis')->assertUnauthorized();
        $this->postJson('/api/devis/1/derogations', [])->assertUnauthorized();
        $this->postJson('/api/derogations-devis/1/accepter')->assertUnauthorized();
    }
}
