<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D4c bis : le devis passe à « En attente de dérogation »
 * pendant une demande, reste modifiable, ne s'envoie pas, puis revient à
 * « En préparation » à la réponse ou à l'annulation.
 */
class DevisDerogationsStatutTest extends TestCase
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

    private function seuil(string $type, float|int $valeur): void
    {
        $this->agirEn($this->direction)
            ->putJson("/api/parametres/seuils/{$type}", ['valeur' => $valeur])
            ->assertOk();
    }

    /**
     * Devis du commercial à 30 % de marge (une ligne à 100 € pour 70 € de revient).
     */
    private function nouveauDevis(): Devis
    {
        $devis = $this->chez(function (): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Dupont']);

            return Devis::create([
                'client_id' => $client->id,
                'titre' => 'Salle de bain',
                'auteur_id' => $this->commercial->id,
                'portee' => 'entreprise',
            ]);
        });
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/lignes", [
            'libelle' => 'Pose de carrelage', 'quantite' => 1, 'prix_unitaire_ht' => 100, 'prix_revient_unitaire' => 70,
        ])->assertCreated();

        return $devis;
    }

    private function demander(Devis $devis, float $valeur, string $type = 'marge_globale_min'): int
    {
        return $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/derogations", [
            'type_seuil' => $type, 'valeur_demandee' => $valeur, 'motif' => 'Client fidèle, chantier de référence.',
        ])->assertCreated()->json('id');
    }

    private function statut(Devis $devis): string
    {
        return (string) $this->chez(fn () => Devis::query()->findOrFail($devis->id)->statut);
    }

    public function test_une_demande_met_le_devis_en_attente_de_derogation(): void
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $this->assertSame('en_preparation', $this->statut($devis));

        $this->demander($devis, 15);
        $this->assertSame('en_attente_derogation', $this->statut($devis));

        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")->assertOk()
            ->assertJsonPath('statut', 'en_attente_derogation');
        $this->assertTrue($this->chez(fn () => Activity::query()
            ->where('event', 'statut')
            ->where('properties->vers', 'en_attente_derogation')
            ->exists()));
    }

    public function test_un_devis_en_attente_reste_modifiable_mais_ne_s_envoie_pas(): void
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $this->demander($devis, 15);

        // Le devis respecte le seuil (30 %), mais une demande attend une réponse.
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->agirEn($this->direction)->postJson("/api/devis/{$devis->id}/envoyer")
            ->assertUnprocessable()->assertJsonValidationErrors('statut');

        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/lignes", [
            'libelle' => 'Plinthes', 'quantite' => 1, 'prix_unitaire_ht' => 50, 'prix_revient_unitaire' => 30,
        ])->assertCreated();
        $this->assertSame('en_attente_derogation', $this->statut($devis));
    }

    public function test_une_reponse_remet_le_devis_en_preparation(): void
    {
        $this->seuil('marge_globale_min', 20);

        $accepte = $this->nouveauDevis();
        $id = $this->demander($accepte, 15);
        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/accepter")->assertOk();
        $this->assertSame('en_preparation', $this->statut($accepte));
        $this->agirEn($this->commercial)->postJson("/api/devis/{$accepte->id}/envoyer")->assertOk()->assertJsonPath('statut', 'envoye');

        $refuse = $this->nouveauDevis();
        $id = $this->demander($refuse, 15);
        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$id}/refuser", ['motif_decision' => 'Marge trop basse.'])->assertOk();
        $this->assertSame('en_preparation', $this->statut($refuse));
    }

    public function test_une_annulation_remet_le_devis_en_preparation(): void
    {
        $this->seuil('marge_globale_min', 20);
        $devis = $this->nouveauDevis();
        $id = $this->demander($devis, 15);

        $this->agirEn($this->commercial)->postJson("/api/derogations-devis/{$id}/annuler")->assertOk();
        $this->assertSame('en_preparation', $this->statut($devis));
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis->id}/envoyer")->assertOk();
    }

    public function test_le_devis_attend_tant_qu_il_reste_une_demande_sans_reponse(): void
    {
        $this->seuil('marge_globale_min', 20);
        $this->seuil('remise_max', 10);
        $devis = $this->nouveauDevis();

        $marge = $this->demander($devis, 15);
        $remise = $this->demander($devis, 20, 'remise_max');
        $this->assertSame('en_attente_derogation', $this->statut($devis));

        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$marge}/refuser", ['motif_decision' => 'Marge trop basse.'])->assertOk();
        $this->assertSame('en_attente_derogation', $this->statut($devis));

        $this->agirEn($this->direction)->postJson("/api/derogations-devis/{$remise}/accepter")->assertOk();
        $this->assertSame('en_preparation', $this->statut($devis));
    }
}
