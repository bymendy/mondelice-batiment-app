<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EtapePipeline;
use App\Models\Opportunite;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Clients / CRM, lot O3 : API du Pipeline (Kanban).
 */
class PipelineKanbanTest extends TestCase
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

    /**
     * @param Client $client
     * @param User|null $commercial
     * @param string $etape
     * @param array<string, mixed> $attributs
     * @return Opportunite
     */
    private function affaire(Client $client, ?User $commercial, string $etape, array $attributs = []): Opportunite
    {
        $etapeId = $this->etape($etape)->id;

        return $this->chez(fn () => Opportunite::create(array_merge([
            'client_id' => $client->id,
            'titre' => 'Affaire ' . $etape,
            'commercial_id' => $commercial?->id,
            'etape_id' => $etapeId,
            'prochaine_action' => 'Appeler',
            'prochaine_action_le' => now()->addDays(3)->toDateString(),
        ], $attributs)));
    }

    /**
     * @param \Illuminate\Testing\TestResponse $reponse
     * @param string $code
     * @return array<string, mixed>
     */
    private function colonne($reponse, string $code): array
    {
        return collect($reponse->json('etapes'))->firstWhere('code', $code);
    }

    public function test_les_dix_colonnes_sont_dans_l_ordre_des_etapes(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $reponse = $this->agirEn($direction)->getJson('/api/pipeline/kanban')->assertOk();

        $this->assertSame(
            ['nouveau', 'a_qualifier', 'visite_planifiee', 'visite_faite', 'devis_en_cours', 'devis_envoye', 'negociation', 'gagne', 'perdu', 'sans_suite'],
            collect($reponse->json('etapes'))->pluck('code')->all()
        );
        $this->assertSame(0, $reponse->json('totaux.en_retard'));
    }

    public function test_totaux_et_chiffre_d_affaires_pondere(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $client = $this->nouveauClient();
        $this->affaire($client, $direction, 'nouveau', ['montant_estime' => 10000]);
        $this->affaire($client, $direction, 'nouveau', ['montant_estime' => 4000, 'probabilite' => 50]);
        $this->affaire($client, $direction, 'visite_faite', ['montant_estime' => 20000]);
        $this->affaire($client, $direction, 'a_qualifier');
        $this->affaire($client, $direction, 'perdu', ['montant_estime' => 5000, 'prochaine_action' => null, 'prochaine_action_le' => null]);

        $reponse = $this->agirEn($direction)->getJson('/api/pipeline/kanban')->assertOk();

        $nouveau = $this->colonne($reponse, 'nouveau');
        $this->assertSame(2, $nouveau['nombre']);
        $this->assertEqualsWithDelta(14000, $nouveau['montant_total'], 0.01);
        // 10000 x 10 % + 4000 x 50 % = 3000
        $this->assertEqualsWithDelta(3000, $nouveau['ca_pondere'], 0.01);
        $this->assertCount(2, $nouveau['affaires']);

        $visite = $this->colonne($reponse, 'visite_faite');
        $this->assertEqualsWithDelta(7000, $visite['ca_pondere'], 0.01);

        $this->assertSame(1, $this->colonne($reponse, 'a_qualifier')['nombre']);
        $this->assertEqualsWithDelta(0, $this->colonne($reponse, 'a_qualifier')['montant_total'], 0.01);

        $perdu = $this->colonne($reponse, 'perdu');
        $this->assertSame(1, $perdu['nombre']);
        $this->assertEqualsWithDelta(0, $perdu['ca_pondere'], 0.01);

        // Les totaux « ouvertes » excluent les affaires cloturees.
        $this->assertSame(4, $reponse->json('totaux.ouvertes.nombre'));
        $this->assertEqualsWithDelta(34000, $reponse->json('totaux.ouvertes.montant'), 0.01);
        $this->assertEqualsWithDelta(10000, $reponse->json('totaux.ouvertes.ca_pondere'), 0.01);
    }

    public function test_les_alertes_signalent_retard_et_action_manquante(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $client = $this->nouveauClient();
        $this->affaire($client, $direction, 'nouveau', ['titre' => 'Dans les temps']);
        $this->affaire($client, $direction, 'nouveau', ['titre' => 'En retard', 'prochaine_action_le' => now()->subDays(2)->toDateString()]);
        $this->affaire($client, $direction, 'nouveau', ['titre' => 'Sans action', 'prochaine_action' => null, 'prochaine_action_le' => null]);
        $this->affaire($client, $direction, 'perdu', ['titre' => 'Cloturee', 'prochaine_action' => null, 'prochaine_action_le' => null]);

        $reponse = $this->agirEn($direction)->getJson('/api/pipeline/kanban')->assertOk();

        $alertes = collect($this->colonne($reponse, 'nouveau')['affaires'])->pluck('alerte', 'titre')->all();
        $this->assertNull($alertes['Dans les temps']);
        $this->assertSame('en_retard', $alertes['En retard']);
        $this->assertSame('sans_action', $alertes['Sans action']);
        $this->assertNull(collect($this->colonne($reponse, 'perdu')['affaires'])->first()['alerte']);

        $this->assertSame(1, $reponse->json('totaux.en_retard'));
        $this->assertSame(1, $reponse->json('totaux.sans_action'));

        // Les plus urgentes d'abord : en retard, puis dans les temps, puis sans date.
        $this->assertSame(['En retard', 'Dans les temps', 'Sans action'], collect($this->colonne($reponse, 'nouveau')['affaires'])->pluck('titre')->all());
    }

    public function test_un_commercial_ne_voit_que_ses_affaires_dans_le_pipeline(): void
    {
        $alice = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $bruno = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $client = $this->nouveauClient();
        $this->affaire($client, $alice, 'nouveau', ['montant_estime' => 1000]);
        $this->affaire($client, $bruno, 'nouveau', ['montant_estime' => 2000]);

        $reponse = $this->agirEn($alice)->getJson('/api/pipeline/kanban?commercial_id=' . $bruno->id)->assertOk();
        $this->assertSame(1, $this->colonne($reponse, 'nouveau')['nombre']);
        $this->assertEqualsWithDelta(1000, $this->colonne($reponse, 'nouveau')['montant_total'], 0.01);

        $this->assertSame(2, $this->colonne($this->agirEn($direction)->getJson('/api/pipeline/kanban'), 'nouveau')['nombre']);
        $filtre = $this->agirEn($direction)->getJson('/api/pipeline/kanban?commercial_id=' . $bruno->id);
        $this->assertSame(1, $this->colonne($filtre, 'nouveau')['nombre']);
        $this->assertEqualsWithDelta(2000, $this->colonne($filtre, 'nouveau')['montant_total'], 0.01);
    }

    public function test_les_affaires_d_une_autre_entreprise_n_apparaissent_pas(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $autre = $this->creerEntreprise();
        $clientEtranger = $this->dansEntreprise($autre, fn () => Client::create(['type' => 'particulier', 'nom' => 'Pirate']));
        $this->dansEntreprise($autre, fn () => Opportunite::create(['client_id' => $clientEtranger->id, 'titre' => 'Secret', 'montant_estime' => 99999]));

        $reponse = $this->agirEn($direction)->getJson('/api/pipeline/kanban')->assertOk();

        $this->assertSame(0, $reponse->json('totaux.ouvertes.nombre'));
    }

    public function test_filtres_par_source_et_par_periode(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $client = $this->nouveauClient();
        $source = $this->chez(fn () => Source::query()->firstOrFail());
        $this->affaire($client, $direction, 'nouveau', ['source_id' => $source->id]);
        $this->affaire($client, $direction, 'nouveau');

        $parSource = $this->agirEn($direction)->getJson('/api/pipeline/kanban?source_id=' . $source->id);
        $this->assertSame(1, $this->colonne($parSource, 'nouveau')['nombre']);

        $demain = now()->addDay()->toDateString();
        $hier = now()->subDay()->toDateString();
        $this->assertSame(0, $this->agirEn($direction)->getJson("/api/pipeline/kanban?du={$demain}")->json('totaux.ouvertes.nombre'));
        $this->assertSame(0, $this->agirEn($direction)->getJson("/api/pipeline/kanban?au={$hier}")->json('totaux.ouvertes.nombre'));
        $this->assertSame(2, $this->agirEn($direction)->getJson("/api/pipeline/kanban?du={$hier}&au={$demain}")->json('totaux.ouvertes.nombre'));
    }

    public function test_la_limite_borne_les_affaires_d_une_colonne_sans_fausser_les_totaux(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $client = $this->nouveauClient();
        foreach ([1000, 2000, 3000] as $montant) {
            $this->affaire($client, $direction, 'nouveau', ['montant_estime' => $montant]);
        }

        $reponse = $this->agirEn($direction)->getJson('/api/pipeline/kanban?limite=2')->assertOk();

        $colonne = $this->colonne($reponse, 'nouveau');
        $this->assertCount(2, $colonne['affaires']);
        $this->assertSame(3, $colonne['nombre']);
        $this->assertTrue($colonne['plus']);
        $this->assertEqualsWithDelta(6000, $colonne['montant_total'], 0.01);

        $this->agirEn($direction)->getJson('/api/pipeline/kanban?limite=0')->assertUnprocessable()->assertJsonValidationErrors('limite');
    }

    public function test_les_colonnes_disent_si_une_etape_se_choisit_a_la_main(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $reponse = $this->agirEn($direction)->getJson('/api/pipeline/kanban')->assertOk();

        foreach (['nouveau', 'a_qualifier', 'visite_planifiee', 'visite_faite', 'devis_en_cours', 'perdu', 'sans_suite'] as $code) {
            $this->assertTrue($this->colonne($reponse, $code)['deplacable'], $code);
        }
        foreach (['devis_envoye', 'negociation', 'gagne'] as $code) {
            $this->assertFalse($this->colonne($reponse, $code)['deplacable'], $code);
        }
    }

    public function test_le_pipeline_exige_la_permission_de_voir_les_affaires(): void
    {
        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Commercial'))->getJson('/api/pipeline/kanban')->assertOk();
        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Conducteur travaux'))->getJson('/api/pipeline/kanban')->assertOk();
    }
}
