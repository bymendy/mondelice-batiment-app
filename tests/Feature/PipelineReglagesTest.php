<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EtapePipeline;
use App\Models\Motif;
use App\Models\Opportunite;
use App\Models\OpportuniteEtape;
use App\Models\Source;
use App\Models\Tenant;
use App\Services\Crm\InitialisationCrm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Clients / CRM, lot O1 : reglages du pipeline (etapes, sources,
 * motifs), source des clients, table des opportunites et historique des
 * etapes en insertion seule.
 */
class PipelineReglagesTest extends TestCase
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

    private function nouveauClient(array $attributs = []): Client
    {
        return $this->chez(fn () => Client::create(array_merge(['type' => 'particulier', 'nom' => 'Dupont'], $attributs)));
    }

    public function test_une_nouvelle_entreprise_recoit_ses_reglages_par_defaut(): void
    {
        $this->chez(function (): void {
            $etapes = EtapePipeline::query()->orderBy('ordre')->get();
            $this->assertSame(
                ['nouveau', 'a_qualifier', 'visite_planifiee', 'visite_faite', 'devis_en_cours', 'devis_envoye', 'negociation', 'gagne', 'perdu', 'sans_suite'],
                $etapes->pluck('code')->all()
            );
            $this->assertSame([10, 15, 25, 35, 45, 55, 70, 100, 0, 0], $etapes->pluck('probabilite_defaut')->all());
            $this->assertTrue($etapes->every(fn ($e) => $e->systeme));
            $this->assertSame('gagnee', $etapes->firstWhere('code', 'gagne')->type);

            $this->assertSame(7, Source::query()->count());
            $this->assertTrue(Source::query()->where('code', 'import_reprise')->firstOrFail()->systeme);
            $this->assertSame(7, Motif::query()->where('type', 'perte')->count());
            $this->assertSame(6, Motif::query()->where('type', 'ecart_demande')->count());
        });
    }

    public function test_l_initialisation_est_idempotente_et_respecte_les_modifications(): void
    {
        DB::table('etapes_pipeline')->where('tenant_id', $this->entreprise->getTenantKey())->where('code', 'nouveau')
            ->update(['nom' => 'Premier contact', 'probabilite_defaut' => 5]);

        InitialisationCrm::pour((string) $this->entreprise->getTenantKey());

        $this->chez(function (): void {
            $this->assertSame(10, EtapePipeline::query()->count());
            $this->assertSame(7, Source::query()->count());
            $this->assertSame(13, Motif::query()->count());
            $nouveau = EtapePipeline::query()->where('code', 'nouveau')->firstOrFail();
            $this->assertSame('Premier contact', $nouveau->nom);
            $this->assertSame(5, $nouveau->probabilite_defaut);
        });
    }

    public function test_chaque_entreprise_a_ses_propres_reglages(): void
    {
        $autre = $this->creerEntreprise();
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $idsAutre = $this->dansEntreprise($autre, fn () => EtapePipeline::query()->pluck('id')->all());

        $reponse = $this->agirEn($direction)->getJson('/api/pipeline/etapes')->assertOk();

        $this->assertCount(10, $reponse->json());
        $this->assertEmpty(array_intersect($idsAutre, collect($reponse->json())->pluck('id')->all()));
        $this->agirEn($direction)->putJson('/api/pipeline/etapes/' . $idsAutre[0], ['nom' => 'Pirate'])->assertNotFound();
    }

    public function test_la_lecture_des_reglages_suit_les_permissions(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');

        $this->agirEn($commercial)->getJson('/api/pipeline/etapes')->assertOk();
        $this->agirEn($commercial)->getJson('/api/pipeline/sources')->assertOk();
        $this->agirEn($commercial)->getJson('/api/pipeline/motifs')->assertOk();

        $this->agirEn($administratif)->getJson('/api/pipeline/sources')->assertOk();
        $this->agirEn($administratif)->getJson('/api/pipeline/etapes')->assertForbidden();
        $this->agirEn($administratif)->getJson('/api/pipeline/motifs')->assertForbidden();
    }

    public function test_seule_la_direction_parametre_le_pipeline(): void
    {
        $etape = $this->etape('nouveau');
        $source = $this->chez(fn () => Source::query()->where('code', 'autre')->firstOrFail());
        $motif = $this->chez(fn () => Motif::query()->where('type', 'perte')->firstOrFail());
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');

        foreach ([$commercial, $administratif] as $utilisateur) {
            $this->agirEn($utilisateur)->putJson("/api/pipeline/etapes/{$etape->id}", ['nom' => 'X'])->assertForbidden();
            $this->agirEn($utilisateur)->postJson('/api/pipeline/sources', ['nom' => 'Salon'])->assertForbidden();
            $this->agirEn($utilisateur)->putJson("/api/pipeline/sources/{$source->id}", ['nom' => 'X'])->assertForbidden();
            $this->agirEn($utilisateur)->deleteJson("/api/pipeline/sources/{$source->id}")->assertForbidden();
            $this->agirEn($utilisateur)->postJson('/api/pipeline/motifs', ['type' => 'perte', 'libelle' => 'X'])->assertForbidden();
            $this->agirEn($utilisateur)->putJson("/api/pipeline/motifs/{$motif->id}", ['libelle' => 'X'])->assertForbidden();
            $this->agirEn($utilisateur)->deleteJson("/api/pipeline/motifs/{$motif->id}")->assertForbidden();
        }
    }

    public function test_la_direction_renomme_une_etape_et_change_sa_probabilite(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $etape = $this->etape('devis_envoye');

        $this->agirEn($direction)->putJson("/api/pipeline/etapes/{$etape->id}", [
            'nom' => 'Devis remis', 'probabilite_defaut' => 60, 'code' => 'pirate', 'ordre' => 99, 'type' => 'gagnee',
        ])->assertOk()->assertJsonPath('nom', 'Devis remis')->assertJsonPath('probabilite_defaut', 60);

        $apres = $this->etape('devis_envoye');
        $this->assertSame('Devis remis', $apres->nom);
        $this->assertSame(60, $apres->probabilite_defaut);
        $this->assertSame(6, $apres->ordre);
        $this->assertSame('ouverte', $apres->type);

        $this->agirEn($direction)->putJson("/api/pipeline/etapes/{$etape->id}", ['probabilite_defaut' => 120])
            ->assertUnprocessable()->assertJsonValidationErrors('probabilite_defaut');
        $this->agirEn($direction)->putJson("/api/pipeline/etapes/{$etape->id}", ['nom' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('nom');
    }

    public function test_la_probabilite_de_gagne_perdu_et_sans_suite_est_fixe(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $gagne = $this->etape('gagne');
        $this->agirEn($direction)->putJson("/api/pipeline/etapes/{$gagne->id}", ['probabilite_defaut' => 80])
            ->assertUnprocessable()->assertJsonValidationErrors('probabilite_defaut');
        $this->agirEn($direction)->putJson("/api/pipeline/etapes/{$gagne->id}", ['nom' => 'Signé', 'probabilite_defaut' => 100])->assertOk();

        foreach (['perdu', 'sans_suite'] as $code) {
            $etape = $this->etape($code);
            $this->agirEn($direction)->putJson("/api/pipeline/etapes/{$etape->id}", ['probabilite_defaut' => 10])
                ->assertUnprocessable()->assertJsonValidationErrors('probabilite_defaut');
        }
    }

    public function test_la_direction_gere_les_sources(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $creee = $this->agirEn($direction)->postJson('/api/pipeline/sources', ['nom' => 'Salon'])
            ->assertCreated()->assertJsonPath('ordre', 8)->assertJsonPath('systeme', false)->json();

        $this->agirEn($direction)->postJson('/api/pipeline/sources', ['nom' => 'salon'])
            ->assertUnprocessable()->assertJsonValidationErrors('nom');

        $this->agirEn($direction)->putJson("/api/pipeline/sources/{$creee['id']}", ['nom' => 'Salon habitat', 'actif' => false, 'code' => 'x', 'systeme' => true])
            ->assertOk()->assertJsonPath('nom', 'Salon habitat')->assertJsonPath('actif', false)->assertJsonPath('systeme', false);

        $this->agirEn($direction)->deleteJson("/api/pipeline/sources/{$creee['id']}")->assertNoContent();
        $this->assertSame(0, $this->chez(fn () => Source::query()->where('nom', 'Salon habitat')->count()));
    }

    public function test_une_source_systeme_ou_utilisee_ne_se_supprime_pas(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $systeme = $this->chez(fn () => Source::query()->where('code', 'import_reprise')->firstOrFail());
        $web = $this->chez(fn () => Source::query()->where('code', 'site_web')->firstOrFail());
        $this->nouveauClient(['source_id' => $web->id]);

        $this->agirEn($direction)->deleteJson("/api/pipeline/sources/{$systeme->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->agirEn($direction)->deleteJson("/api/pipeline/sources/{$web->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('source');

        $this->agirEn($direction)->putJson("/api/pipeline/sources/{$web->id}", ['actif' => false])->assertOk();
    }

    public function test_la_direction_gere_les_motifs(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $this->assertCount(6, $this->agirEn($direction)->getJson('/api/pipeline/motifs?type=ecart_demande')->assertOk()->json());

        $cree = $this->agirEn($direction)->postJson('/api/pipeline/motifs', ['type' => 'perte', 'libelle' => 'Trop loin'])
            ->assertCreated()->assertJsonPath('ordre', 8)->json();

        $this->agirEn($direction)->postJson('/api/pipeline/motifs', ['type' => 'perte', 'libelle' => 'trop loin'])
            ->assertUnprocessable()->assertJsonValidationErrors('libelle');
        $this->agirEn($direction)->postJson('/api/pipeline/motifs', ['type' => 'inconnu', 'libelle' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        // Meme libelle dans l'autre liste : accepte.
        $this->agirEn($direction)->postJson('/api/pipeline/motifs', ['type' => 'ecart_demande', 'libelle' => 'Trop loin'])->assertCreated();

        $this->agirEn($direction)->putJson("/api/pipeline/motifs/{$cree['id']}", ['libelle' => 'Trop éloigné', 'type' => 'ecart_demande'])
            ->assertOk()->assertJsonPath('libelle', 'Trop éloigné')->assertJsonPath('type', 'perte');

        $this->agirEn($direction)->deleteJson("/api/pipeline/motifs/{$cree['id']}")->assertNoContent();
    }

    public function test_un_motif_utilise_ne_se_supprime_pas(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $motif = $this->chez(fn () => Motif::query()->where('type', 'perte')->firstOrFail());
        $client = $this->nouveauClient();
        $this->chez(fn () => Opportunite::create(['client_id' => $client->id, 'titre' => 'Salle de bain', 'motif_perte_id' => $motif->id]));

        $this->agirEn($direction)->deleteJson("/api/pipeline/motifs/{$motif->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->agirEn($direction)->putJson("/api/pipeline/motifs/{$motif->id}", ['actif' => false])->assertOk();
    }

    public function test_un_client_porte_une_source_de_son_entreprise(): void
    {
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $web = $this->chez(fn () => Source::query()->where('code', 'site_web')->firstOrFail());
        $autre = $this->creerEntreprise();
        $sourceAutre = $this->dansEntreprise($autre, fn () => Source::query()->firstOrFail());

        $id = $this->agirEn($commercial)->postJson('/api/clients', ['type' => 'particulier', 'nom' => 'Durand', 'source_id' => $web->id])
            ->assertCreated()->assertJsonPath('source_id', $web->id)->json('id');
        $this->nouveauClient(['nom' => 'Autre']);

        $this->agirEn($commercial)->postJson('/api/clients', ['type' => 'particulier', 'nom' => 'X', 'source_id' => $sourceAutre->id])
            ->assertUnprocessable()->assertJsonValidationErrors('source_id');

        $noms = collect($this->agirEn($commercial)->getJson("/api/clients?source_id={$web->id}")->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$id], $noms->all());
        $this->assertFalse(Schema::hasColumn('clients', 'source_lead'));
    }

    public function test_une_affaire_entre_a_l_etape_nouveau_avec_sa_probabilite(): void
    {
        $client = $this->nouveauClient();

        $affaire = $this->chez(fn () => Opportunite::create([
            'client_id' => $client->id, 'titre' => 'Renovation cuisine', 'metiers' => ['plomberie', 'carrelage'], 'montant_estime' => 12500,
        ]));

        $this->chez(function () use ($affaire): void {
            $recharge = Opportunite::query()->findOrFail($affaire->id);
            $this->assertSame('nouveau', $recharge->etape->code);
            $this->assertSame(10, $recharge->probabilite);
            $this->assertSame(['plomberie', 'carrelage'], $recharge->metiers);
            $this->assertSame('12500.00', $recharge->montant_estime);
            $this->assertTrue($recharge->client->is(Client::query()->findOrFail($recharge->client_id)));
        });
    }

    public function test_le_schema_des_opportunites_est_celui_du_crm(): void
    {
        $this->assertTrue(Schema::hasTable('opportunites'));
        $this->assertFalse(Schema::hasTable('opportunities'));
        foreach (['client_id', 'contact_id', 'adresse_chantier_id', 'titre', 'metiers', 'montant_estime', 'etape_id', 'probabilite', 'date_decision_prevue', 'source_id', 'commercial_id', 'devis_principal_id', 'motif_perte_id', 'concurrent', 'prochaine_action', 'prochaine_action_le', 'gagnee_le', 'perdue_le', 'deleted_at'] as $colonne) {
            $this->assertTrue(Schema::hasColumn('opportunites', $colonne), $colonne);
        }
        $this->assertFalse(Schema::hasColumn('opportunites', 'status'));
        $this->assertFalse(Schema::hasColumn('opportunites', 'source'));

        $type = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'opportunites' AND column_name = 'tenant_id'");
        $this->assertSame('character varying', $type->data_type);
        $cle = DB::selectOne("SELECT 1 AS ok FROM information_schema.table_constraints WHERE table_name = 'opportunites' AND constraint_type = 'FOREIGN KEY' AND constraint_name = 'opportunites_tenant_id_foreign'");
        $this->assertNotNull($cle);
    }

    public function test_la_base_refuse_une_probabilite_hors_limites(): void
    {
        $client = $this->nouveauClient();

        $this->expectException(QueryException::class);
        $this->chez(fn () => Opportunite::create(['client_id' => $client->id, 'titre' => 'X', 'probabilite' => 150]));
    }

    public function test_l_historique_des_etapes_s_ecrit_avec_les_valeurs_par_defaut(): void
    {
        $client = $this->nouveauClient();
        $affaire = $this->chez(fn () => Opportunite::create(['client_id' => $client->id, 'titre' => 'Toiture']));
        $nouveau = $this->etape('nouveau');
        $qualifier = $this->etape('a_qualifier');

        $ligne = $this->chez(fn () => OpportuniteEtape::create([
            'opportunite_id' => $affaire->id, 'etape_depuis_id' => $nouveau->id, 'etape_vers_id' => $qualifier->id,
        ]));

        $this->chez(function () use ($ligne, $affaire): void {
            $relue = OpportuniteEtape::query()->findOrFail($ligne->id);
            $this->assertSame('human', $relue->triggered_by);
            $this->assertFalse($relue->retour_arriere);
            $this->assertNotNull($relue->change_le);
            $this->assertCount(1, Opportunite::query()->findOrFail($affaire->id)->historique);
        });
    }

    public function test_l_historique_des_etapes_refuse_toute_modification(): void
    {
        $client = $this->nouveauClient();
        $affaire = $this->chez(fn () => Opportunite::create(['client_id' => $client->id, 'titre' => 'Toiture']));
        $nouveau = $this->etape('nouveau');
        $ligne = $this->chez(fn () => OpportuniteEtape::create([
            'opportunite_id' => $affaire->id, 'etape_vers_id' => $nouveau->id,
        ]));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('insertion seule');
        DB::table('opportunite_etapes')->where('id', $ligne->id)->update(['retour_arriere' => true]);
    }

    public function test_l_historique_des_etapes_refuse_toute_suppression(): void
    {
        $client = $this->nouveauClient();
        $affaire = $this->chez(fn () => Opportunite::create(['client_id' => $client->id, 'titre' => 'Toiture']));
        $nouveau = $this->etape('nouveau');
        $ligne = $this->chez(fn () => OpportuniteEtape::create([
            'opportunite_id' => $affaire->id, 'etape_vers_id' => $nouveau->id,
        ]));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('insertion seule');
        DB::table('opportunite_etapes')->where('id', $ligne->id)->delete();
    }

    public function test_le_devis_pointe_vers_la_nouvelle_table_des_affaires(): void
    {
        $client = $this->nouveauClient();
        $affaire = $this->chez(fn () => Opportunite::create(['client_id' => $client->id, 'titre' => 'Toiture']));

        $this->chez(function () use ($client, $affaire): void {
            $devis = \App\Models\Quote::create(['client_id' => $client->id, 'opportunity_id' => $affaire->id]);
            $this->assertTrue($devis->opportunity->is($affaire));
            $this->assertCount(1, $affaire->devis);
        });
    }
}
