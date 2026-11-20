<?php

namespace Tests\Feature;

use App\Models\AlerteSeuil;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AlerteSeuilDevis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D6 : un devis privé n'est vu que de son auteur et de ceux
 * qui ont `devis.voir_prives_autres` (la Direction), partout dans l'API.
 */
class DevisPrivesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private User $administratif;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');
        $this->auteur = $this->creerUtilisateur($this->entreprise, 'Commercial');
    }

    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    private function nouveauDevis(string $portee): Devis
    {
        $devis = $this->chez(function () use ($portee): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Dupont']);

            return Devis::create([
                'client_id' => $client->id,
                'titre' => 'Salle de bain',
                'auteur_id' => $this->auteur->id,
                'portee' => $portee,
            ]);
        });
        $this->ligne($devis, 100, 70)->assertCreated();

        return $devis;
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function ligne(Devis $devis, float $prix, float $revient)
    {
        return $this->agirEn($this->auteur)->postJson("/api/devis/{$devis->id}/lignes", [
            'libelle' => 'Pose de carrelage', 'quantite' => 1, 'prix_unitaire_ht' => $prix, 'prix_revient_unitaire' => $revient,
        ]);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function utilisateurAvec(string $role, array $permissions): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->creerUtilisateur($this->entreprise, $role);
    }

    public function test_un_devis_prive_n_est_vu_que_de_son_auteur_et_de_la_direction(): void
    {
        $prive = $this->nouveauDevis('prive');

        $this->agirEn($this->auteur)->getJson("/api/devis/{$prive->id}")->assertOk();
        $this->agirEn($this->direction)->getJson("/api/devis/{$prive->id}")->assertOk();

        foreach (['Commercial', 'Conducteur travaux', 'Administratif'] as $role) {
            $autre = $this->creerUtilisateur($this->entreprise, $role);
            $this->agirEn($autre)->getJson("/api/devis/{$prive->id}")->assertNotFound();
            $ids = collect($this->agirEn($autre)->getJson('/api/devis')->assertOk()->json('data'))->pluck('id');
            $this->assertFalse($ids->contains($prive->id), $role);
        }
    }

    public function test_la_permission_du_devis_remplace_celle_des_affaires(): void
    {
        $prive = $this->nouveauDevis('prive');

        // Voir toutes les affaires ne donne plus accès aux devis privés.
        $affaires = $this->utilisateurAvec('Lecteur des affaires', ['devis.voir', 'opportunites.voir', 'opportunites.voir_toutes']);
        $this->agirEn($affaires)->getJson("/api/devis/{$prive->id}")->assertNotFound();

        $prives = $this->utilisateurAvec('Lecteur des devis prives', ['devis.voir', 'devis.voir_prives_autres']);
        $this->agirEn($prives)->getJson("/api/devis/{$prive->id}")->assertOk();
    }

    public function test_les_alertes_d_un_devis_prive_ne_vont_qu_a_la_direction(): void
    {
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_globale_min', ['valeur' => 20])->assertOk();
        $prive = $this->nouveauDevis('prive');

        $this->ligne($prive, 100, 95)->assertUnprocessable();

        Notification::assertSentTo($this->direction, AlerteSeuilDevis::class);
        Notification::assertNotSentTo($this->administratif, AlerteSeuilDevis::class);
        $this->assertSame(1, $this->chez(fn () => AlerteSeuil::query()->count()));
        $this->agirEn($this->direction)->getJson('/api/alertes-seuils')->assertOk()->assertJsonCount(1, 'data');
        $this->agirEn($this->administratif)->getJson('/api/alertes-seuils')->assertOk()->assertJsonCount(0, 'data');

        // Sur un devis de l'entreprise, l'Administratif reste prévenu.
        $partage = $this->nouveauDevis('entreprise');
        $this->ligne($partage, 100, 95)->assertUnprocessable();

        Notification::assertSentTo($this->administratif, AlerteSeuilDevis::class);
        $this->agirEn($this->direction)->getJson('/api/alertes-seuils')->assertOk()->assertJsonCount(2, 'data');
        $this->agirEn($this->administratif)->getJson('/api/alertes-seuils')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.devis.id', $partage->id);
    }

    public function test_les_derogations_et_les_avenants_d_un_devis_prive_restent_fermes_aux_autres(): void
    {
        $prive = $this->nouveauDevis('prive');
        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($collegue)->getJson("/api/devis/{$prive->id}/derogations")->assertNotFound();
        $this->agirEn($collegue)->postJson("/api/devis/{$prive->id}/derogations", [
            'type_seuil' => 'marge_globale_min', 'valeur_demandee' => 10, 'motif' => 'Tentative sur le devis d\'un autre.',
        ])->assertNotFound();
        $this->agirEn($collegue)->postJson("/api/devis/{$prive->id}/avenants")->assertNotFound();
        $this->agirEn($collegue)->postJson("/api/devis/{$prive->id}/envoyer")->assertNotFound();

        $this->agirEn($this->auteur)->getJson("/api/devis/{$prive->id}/derogations")->assertOk();
        $this->agirEn($this->direction)->getJson("/api/devis/{$prive->id}/derogations")->assertOk();
    }
}
