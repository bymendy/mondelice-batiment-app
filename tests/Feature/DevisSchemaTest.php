<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\LotDevis;
use App\Models\Opportunite;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D0 : tables devis, lots_devis, lignes_devis et calcul
 * des totaux d'un devis.
 */
class DevisSchemaTest extends TestCase
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

    private function nouveauClient(): Client
    {
        return $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));
    }

    private function nouveauDevis(Client $client, array $attributs = []): Devis
    {
        return $this->chez(fn () => Devis::create(array_merge(['client_id' => $client->id, 'titre' => 'Salle de bain'], $attributs)));
    }

    public function test_les_anciennes_tables_sont_remplacees(): void
    {
        $this->assertFalse(Schema::hasTable('quotes'));
        $this->assertFalse(Schema::hasTable('quote_lines'));

        foreach (['devis', 'lots_devis', 'lignes_devis'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertSame(
                'character varying',
                DB::selectOne("select data_type from information_schema.columns where table_name = ? and column_name = 'tenant_id'", [$table])->data_type,
                $table
            );
        }

        $this->assertTrue(Schema::hasColumns('devis', ['numero', 'statut', 'devis_parent_id', 'rang_avenant', 'auteur_id', 'portee', 'validite_jours', 'accepte_le', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('lignes_devis', ['devis_id', 'lot_id', 'emplacement_id', 'libelle', 'quantite', 'prix_unitaire_ht', 'taux_tva', 'prix_revient_unitaire', 'dtu_reference']));
    }

    public function test_un_devis_neuf_a_ses_valeurs_par_defaut(): void
    {
        $devis = $this->nouveauDevis($this->nouveauClient())->refresh();

        $this->assertSame('en_preparation', $devis->statut);
        $this->assertSame('prive', $devis->portee);
        $this->assertSame(30, $devis->validite_jours);
        $this->assertNull($devis->numero);
        $this->assertSame('0.00', $devis->total_ttc);
    }

    public function test_lots_et_lignes_se_rattachent_au_devis(): void
    {
        $client = $this->nouveauClient();
        $devis = $this->nouveauDevis($client);

        $this->chez(function () use ($devis): void {
            $lot = LotDevis::create(['devis_id' => $devis->id, 'type' => 'lot', 'libelle' => 'Platrerie', 'ordre' => 2]);
            $cuisine = LotDevis::create(['devis_id' => $devis->id, 'type' => 'emplacement', 'libelle' => 'Cuisine', 'ordre' => 1]);
            $ligne = $devis->lignes()->create([
                'lot_id' => $lot->id, 'emplacement_id' => $cuisine->id, 'libelle' => 'Pose de placo',
                'quantite' => 12.5, 'unite' => 'm2', 'prix_unitaire_ht' => 40, 'taux_tva' => 10, 'ordre' => 1,
            ]);

            $this->assertSame(['Cuisine', 'Platrerie'], $devis->lots->pluck('libelle')->all());
            $this->assertTrue($ligne->lot->is($lot));
            $this->assertTrue($ligne->emplacement->is($cuisine));
            $this->assertCount(1, $lot->lignes);
            $this->assertCount(1, $cuisine->lignes);
            $this->assertTrue($ligne->devis->is($devis));
        });
    }

    public function test_les_totaux_se_calculent_et_s_enregistrent(): void
    {
        $devis = $this->nouveauDevis($this->nouveauClient());

        $this->chez(function () use ($devis): void {
            $devis->lignes()->create(['libelle' => 'Carrelage', 'quantite' => 2, 'prix_unitaire_ht' => 74.75, 'taux_tva' => 10, 'ordre' => 1]);
            $devis->lignes()->create(['libelle' => 'Peinture', 'quantite' => 10, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'ordre' => 2]);

            $detail = $devis->recalculerTotaux();

            $this->assertSame(349.5, $detail['total_ht']);
            $this->assertCount(2, $detail['par_taux']);

            $relu = Devis::query()->findOrFail($devis->id);
            $this->assertSame('349.50', $relu->total_ht);
            $this->assertSame('54.95', $relu->total_tva);
            $this->assertSame('404.45', $relu->total_ttc);
        });
    }

    public function test_un_devis_garde_son_lien_avec_l_affaire_et_son_client(): void
    {
        $client = $this->nouveauClient();
        $affaire = $this->chez(fn () => Opportunite::create(['client_id' => $client->id, 'titre' => 'Toiture']));
        $devis = $this->nouveauDevis($client, ['opportunite_id' => $affaire->id]);

        $this->chez(function () use ($devis, $affaire, $client): void {
            $this->assertTrue($devis->opportunite->is($affaire));
            $this->assertTrue($devis->client->is($client));
            $this->assertCount(1, $affaire->devis);
        });
    }

    public function test_un_avenant_pointe_vers_son_devis_d_origine(): void
    {
        $client = $this->nouveauClient();
        $origine = $this->nouveauDevis($client);
        $avenant = $this->nouveauDevis($client);
        $this->chez(fn () => $avenant->forceFill(['devis_parent_id' => $origine->id, 'rang_avenant' => 1])->save());

        $this->chez(function () use ($origine, $avenant): void {
            $this->assertTrue(Devis::query()->findOrFail($avenant->id)->parent->is($origine));
            $this->assertCount(1, $origine->avenants);
        });
    }

    public function test_la_suppression_est_douce(): void
    {
        $devis = $this->nouveauDevis($this->nouveauClient());

        $this->chez(function () use ($devis): void {
            $devis->delete();
            $this->assertSame(0, Devis::query()->count());
            $this->assertNotNull(Devis::withTrashed()->findOrFail($devis->id)->deleted_at);
        });
    }

    public function test_une_entreprise_ne_voit_pas_les_devis_d_une_autre(): void
    {
        $this->nouveauDevis($this->nouveauClient());
        $autre = $this->creerEntreprise();

        $this->assertSame(0, $this->dansEntreprise($autre, fn () => Devis::query()->count()));
        $this->assertSame(0, $this->dansEntreprise($autre, fn () => LigneDevis::query()->count()));
    }

    public function test_deux_entreprises_peuvent_avoir_le_meme_numero(): void
    {
        $this->nouveauDevis($this->nouveauClient(), ['numero' => 'DE2026-0001']);
        $autre = $this->creerEntreprise();
        $clientAutre = $this->dansEntreprise($autre, fn () => Client::create(['type' => 'particulier', 'nom' => 'Martin']));

        $devis = $this->dansEntreprise($autre, fn () => Devis::create(['client_id' => $clientAutre->id, 'numero' => 'DE2026-0001']));

        $this->assertSame('DE2026-0001', $devis->numero);
    }

    public function test_la_base_refuse_deux_devis_de_meme_numero_dans_une_entreprise(): void
    {
        $client = $this->nouveauClient();
        $this->nouveauDevis($client, ['numero' => 'DE2026-0001']);

        $this->expectException(QueryException::class);
        $this->nouveauDevis($client, ['numero' => 'DE2026-0001']);
    }

    public function test_la_base_refuse_une_portee_inconnue(): void
    {
        $client = $this->nouveauClient();

        $this->expectException(QueryException::class);
        DB::table('devis')->insert(['tenant_id' => $this->entreprise->getTenantKey(), 'client_id' => $client->id, 'portee' => 'secret']);
    }
}
