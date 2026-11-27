<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DeductionDevis;
use App\Models\DerogationDevis;
use App\Models\Devis;
use App\Models\DevisParametres;
use App\Models\DevisTauxHoraire;
use App\Models\LigneDevis;
use App\Models\LigneDevisComposant;
use App\Models\LotDevis;
use App\Models\Opportunite;
use App\Models\Tenant;
use App\Models\User;
use App\States\Devis\DevisState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot V1 : versions d'un devis (numérotation, copie, statut
 * « Non retenue », fermeture des autres versions à la signature).
 */
class DevisVersionsTest extends TestCase
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
     * Devis créé par l'API (numéroté, avec son affaire) : un lot, deux
     * lignes (1 000 € et 2 × 250 € HT), un sous-détail, une remise de 10 %.
     *
     * @return array{id: int, numero: string}
     */
    private function nouveauDevis(string $portee = 'entreprise'): array
    {
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));
        $reponse = $this->agirEn($this->commercial)->postJson('/api/devis', [
            'client_id' => $client->id, 'titre' => 'Rénovation de la salle de bain', 'portee' => $portee,
        ])->assertCreated();
        $id = (int) $reponse->json('id');

        $lotId = (int) $this->agirEn($this->commercial)->postJson("/api/devis/{$id}/lots", [
            'type' => 'lot', 'libelle' => 'Plomberie',
        ])->assertCreated()->json('id');

        $this->agirEn($this->commercial)->postJson("/api/devis/{$id}/lignes", [
            'libelle' => 'Pose de la douche', 'quantite' => 1, 'prix_unitaire_ht' => 1000, 'prix_revient_unitaire' => 600, 'lot_id' => $lotId,
        ])->assertCreated();
        $this->agirEn($this->commercial)->postJson("/api/devis/{$id}/lignes", [
            'libelle' => 'Faïence', 'quantite' => 2, 'prix_unitaire_ht' => 250, 'prix_revient_unitaire' => 150,
        ])->assertCreated();

        $this->chez(function () use ($id): void {
            $ligne = LigneDevis::query()->where('devis_id', $id)->where('libelle', 'Pose de la douche')->firstOrFail();
            LigneDevisComposant::create([
                'ligne_devis_id' => $ligne->id, 'type' => 'fourniture_libre', 'designation' => 'Receveur extra-plat',
                'quantite' => 1, 'unite' => 'u', 'prix_achat_ht' => 300, 'prix_vente_ht' => 450, 'ordre' => 1,
            ]);
            DeductionDevis::create(['devis_id' => $id, 'libelle' => 'Geste commercial', 'type' => 'pourcentage', 'valeur' => 10, 'ordre' => 1]);
            DevisParametres::query()->where('devis_id', $id)->update(['coefficient_zone' => 1.10]);
            Devis::query()->findOrFail($id)->recalculerTotaux();
        });

        return ['id' => $id, 'numero' => (string) $reponse->json('numero')];
    }

    private function passerA(int $devisId, string $statut): void
    {
        $this->chez(fn () => Devis::query()->findOrFail($devisId)->forceFill(['statut' => $statut])->save());
    }

    private function enBase(int $devisId): Devis
    {
        return $this->chez(fn () => Devis::query()->findOrFail($devisId));
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function creerVersion(int $devisId, array $corps = [], ?User $auteur = null)
    {
        return $this->agirEn($auteur ?? $this->commercial)->postJson("/api/devis/{$devisId}/versions", $corps);
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function accepter(int $devisId)
    {
        return $this->agirEn($this->commercial)->postJson("/api/devis/{$devisId}/statut", [
            'statut' => 'accepte', 'confirmation' => true, 'motif' => 'Accord du client', 'preuve_acceptation' => 'Devis signé',
        ]);
    }

    public function test_une_version_est_numerotee_et_reprend_tout_le_devis(): void
    {
        $devis = $this->nouveauDevis();
        $origine = $this->enBase($devis['id']);
        $this->assertEqualsWithDelta(1350.0, (float) $origine->total_ht, 0.001);

        $reponse = $this->creerVersion($devis['id'])->assertCreated()
            ->assertJsonPath('numero', $devis['numero'] . '-V2')
            ->assertJsonPath('rang_version', 2)
            ->assertJsonPath('version_origine_id', $devis['id'])
            ->assertJsonPath('devis_parent_id', null)
            ->assertJsonPath('statut', 'en_preparation')
            ->assertJsonPath('statut_libelle', 'En préparation')
            ->assertJsonPath('titre', 'Rénovation de la salle de bain')
            ->assertJsonPath('client_id', $origine->client_id)
            ->assertJsonPath('opportunite_id', $origine->opportunite_id)
            ->assertJsonPath('portee', 'entreprise')
            ->assertJsonPath('auteur_id', $this->commercial->id)
            ->assertJsonPath('mode', 'remplace')
            ->assertJsonPath('version_source.numero', $devis['numero'])
            ->assertJsonPath('version.rang', 2)
            ->assertJsonPath('version.nombre_de_versions', 2)
            ->assertJsonCount(2, 'versions');
        $versionId = (int) $reponse->json('id');
        $this->assertEqualsWithDelta(1350.0, (float) $reponse->json('total_ht'), 0.001);

        $this->chez(function () use ($devis, $versionId): void {
            // Un lot, deux lignes, un sous-détail, une remise : tout est recopié, sur de nouveaux enregistrements.
            $lot = LotDevis::query()->where('devis_id', $versionId)->sole();
            $this->assertSame('Plomberie', $lot->libelle);

            $lignes = LigneDevis::query()->where('devis_id', $versionId)->orderBy('id')->get();
            $this->assertCount(2, $lignes);
            $douche = $lignes->firstWhere('libelle', 'Pose de la douche');
            $origineDouche = LigneDevis::query()->where('devis_id', $devis['id'])->where('libelle', 'Pose de la douche')->sole();
            $this->assertSame($lot->id, $douche->lot_id);
            $this->assertSame($origineDouche->id, $douche->ligne_origine_id);
            $this->assertEqualsWithDelta(1000.0, (float) $douche->prix_unitaire_ht, 0.001);
            $this->assertEqualsWithDelta(600.0, (float) $douche->prix_revient_unitaire, 0.001);
            $this->assertNull($lignes->firstWhere('libelle', 'Faïence')->lot_id);
            $this->assertEqualsWithDelta(2.0, (float) $lignes->firstWhere('libelle', 'Faïence')->quantite, 0.001);

            $composant = LigneDevisComposant::query()->where('ligne_devis_id', $douche->id)->sole();
            $this->assertSame('Receveur extra-plat', $composant->designation);
            $this->assertEqualsWithDelta(300.0, (float) $composant->prix_achat_ht, 0.001);
            $this->assertEqualsWithDelta(450.0, (float) $composant->prix_vente_ht, 0.001);

            $remise = DeductionDevis::query()->where('devis_id', $versionId)->sole();
            $this->assertSame('Geste commercial', $remise->libelle);
            $this->assertEqualsWithDelta(10.0, (float) $remise->valeur, 0.001);

            // Paramètres et taux horaires du devis.
            $this->assertEqualsWithDelta(1.10, (float) DevisParametres::query()->where('devis_id', $versionId)->sole()->coefficient_zone, 0.001);
            $this->assertSame(
                DevisTauxHoraire::query()->where('devis_id', $devis['id'])->count(),
                DevisTauxHoraire::query()->where('devis_id', $versionId)->count()
            );

            // Le devis d'origine garde son contenu.
            $this->assertSame(2, LigneDevis::query()->where('devis_id', $devis['id'])->count());
            $this->assertSame(1, DeductionDevis::query()->where('devis_id', $devis['id'])->count());
        });

        // La version est un brouillon : elle se modifie.
        $this->agirEn($this->commercial)->postJson("/api/devis/{$versionId}/lignes", [
            'libelle' => 'Meuble vasque', 'quantite' => 1, 'prix_unitaire_ht' => 500,
        ])->assertCreated();
        $this->assertSame(2, $this->chez(fn () => LigneDevis::query()->where('devis_id', $devis['id'])->count()));
    }

    public function test_les_rangs_se_suivent_et_ne_sont_jamais_reutilises(): void
    {
        $devis = $this->nouveauDevis();
        $v2 = (int) $this->creerVersion($devis['id'])->assertCreated()->json('id');

        // Depuis une version, le numéro repart du premier devis.
        $v3 = (int) $this->creerVersion($v2)->assertCreated()
            ->assertJsonPath('numero', $devis['numero'] . '-V3')
            ->assertJsonPath('version_origine_id', $devis['id'])
            ->json('id');

        // Une version supprimée garde son rang.
        $this->chez(fn () => Devis::query()->findOrFail($v3)->delete());
        $this->creerVersion($v2)->assertCreated()->assertJsonPath('numero', $devis['numero'] . '-V4');

        // Un nouveau devis prend le numéro suivant, sans tenir compte des versions.
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Martin']));
        $suivant = $this->agirEn($this->commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Cuisine'])
            ->assertCreated()->json('numero');
        $this->assertStringNotContainsString('-V', (string) $suivant);
        $this->assertNotSame($devis['numero'], $suivant);
    }

    public function test_remplacer_ferme_la_version_copiee(): void
    {
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'envoye');

        $reponse = $this->creerVersion($devis['id'], ['titre' => 'Salle de bain sans faïence'])->assertCreated()
            ->assertJsonPath('titre', 'Salle de bain sans faïence')
            ->assertJsonPath('version_source.statut', 'non_retenu')
            ->assertJsonPath('version_source.statut_libelle', 'Non retenue');
        $versionId = (int) $reponse->json('id');

        $origine = $this->enBase($devis['id']);
        $this->assertSame('non_retenu', (string) $origine->statut);
        $this->assertSame('remplace', $origine->non_retenu_motif);
        $this->assertSame($versionId, $origine->non_retenu_par_id);
        $this->assertNotNull($origine->non_retenu_le);

        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis['id']}")->assertOk()
            ->assertJsonPath('statut_libelle', 'Non retenue')
            ->assertJsonPath('macro_etat', 'decide')
            ->assertJsonPath('version.rang', 1)
            ->assertJsonPath('version.non_retenu_motif', 'remplace')
            ->assertJsonPath('version.non_retenu_au_profit_de.numero', $devis['numero'] . '-V2');

        // Une version non retenue ne se modifie plus et ne change plus de statut à la main.
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis['id']}/lignes", [
            'libelle' => 'Ajout', 'quantite' => 1, 'prix_unitaire_ht' => 10,
        ])->assertUnprocessable();
        $this->accepter($devis['id'])->assertUnprocessable();

        $this->assertSame(1, $this->chez(fn () => Activity::query()
            ->where('event', 'statut')
            ->where('subject_id', $devis['id'])
            ->where('properties->vers', 'non_retenu')
            ->where('properties->motif', 'remplace')
            ->count()));
    }

    public function test_proposer_en_plus_garde_les_deux_versions(): void
    {
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'envoye');

        $this->creerVersion($devis['id'], ['mode' => 'en_plus'])->assertCreated()
            ->assertJsonPath('mode', 'en_plus')
            ->assertJsonPath('version_source.statut', 'envoye');
        $this->assertSame('envoye', (string) $this->enBase($devis['id'])->statut);

        $this->creerVersion($devis['id'], ['mode' => 'autre'])->assertUnprocessable()->assertJsonValidationErrors('mode');
    }

    public function test_une_version_deja_decidee_garde_son_statut(): void
    {
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'refuse');

        $this->creerVersion($devis['id'])->assertCreated()->assertJsonPath('version_source.statut', 'refuse');
        $this->assertSame('refuse', (string) $this->enBase($devis['id'])->statut);
    }

    public function test_la_signature_d_une_version_ferme_les_autres(): void
    {
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'envoye');
        $v2 = (int) $this->creerVersion($devis['id'], ['mode' => 'en_plus'])->assertCreated()->json('id');
        $v3 = (int) $this->creerVersion($v2, ['mode' => 'en_plus'])->assertCreated()->json('id');
        $this->passerA($v2, 'envoye');
        // La version 3 reste en préparation ; un quatrième exemplaire a déjà été refusé.
        $v4 = (int) $this->creerVersion($v2, ['mode' => 'en_plus'])->assertCreated()->json('id');
        $this->passerA($v4, 'refuse');

        $this->accepter($v2)->assertOk()->assertJsonPath('statut', 'accepte');

        $this->assertSame('accepte', (string) $this->enBase($v2)->statut);
        foreach ([$devis['id'], $v3] as $autre) {
            $fermee = $this->enBase($autre);
            $this->assertSame('non_retenu', (string) $fermee->statut);
            $this->assertSame('autre_version_acceptee', $fermee->non_retenu_motif);
            $this->assertSame($v2, $fermee->non_retenu_par_id);
        }
        $this->assertSame('refuse', (string) $this->enBase($v4)->statut);

        // Après la signature : plus de version, mais un avenant sur la version signée.
        foreach ([$devis['id'], $v2, $v3] as $id) {
            $this->creerVersion($id)->assertUnprocessable()->assertJsonValidationErrors('devis');
        }
        $this->agirEn($this->commercial)->getJson("/api/devis/{$v2}")->assertOk()->assertJsonPath('peut_creer_version', false);

        $avenantId = (int) $this->agirEn($this->commercial)->postJson("/api/devis/{$v2}/avenants")->assertCreated()
            ->assertJsonPath('numero', $devis['numero'] . '-V2-A1')
            ->json('id');
        // Pas de version d'un avenant.
        $this->creerVersion($avenantId)->assertUnprocessable()->assertJsonValidationErrors('devis');
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis['id']}/avenants")->assertUnprocessable();
    }

    public function test_une_version_ne_cree_pas_d_affaire_et_ne_la_deplace_pas(): void
    {
        $devis = $this->nouveauDevis();
        $affaireId = (int) $this->enBase($devis['id'])->opportunite_id;
        $this->passerA($devis['id'], 'envoye');
        $avant = $this->chez(fn () => Opportunite::query()->findOrFail($affaireId));

        $this->creerVersion($devis['id'])->assertCreated()->assertJsonPath('opportunite_id', $affaireId);

        $apres = $this->chez(fn () => Opportunite::query()->findOrFail($affaireId));
        $this->assertSame(1, $this->chez(fn () => Opportunite::query()->count()));
        $this->assertSame($avant->etape_id, $apres->etape_id);
        $this->assertEquals($avant->montant_estime, $apres->montant_estime);
    }

    public function test_les_derogations_ne_sont_pas_recopiees(): void
    {
        $devis = $this->nouveauDevis();
        $this->chez(function () use ($devis): void {
            foreach (['marge_globale_min' => DerogationDevis::ACCEPTEE, 'remise_max' => DerogationDevis::EN_ATTENTE] as $type => $statut) {
                DerogationDevis::create([
                    'devis_id' => $devis['id'], 'type_seuil' => $type, 'statut' => $statut, 'seuil' => 25,
                    'valeur_demandee' => 20, 'motif' => 'Client fidèle', 'demandeur_id' => $this->commercial->id, 'demandee_le' => now(),
                ]);
            }
        });
        $this->passerA($devis['id'], 'en_attente_derogation');

        $versionId = (int) $this->creerVersion($devis['id'])->assertCreated()->assertJsonPath('statut', 'en_preparation')->json('id');

        $this->chez(function () use ($devis, $versionId): void {
            $this->assertSame(0, DerogationDevis::query()->where('devis_id', $versionId)->count());
            // La demande en attente sur la version remplacée est annulée ; la décision déjà prise reste dans l'historique.
            $this->assertSame(0, DerogationDevis::query()->where('devis_id', $devis['id'])->where('statut', DerogationDevis::EN_ATTENTE)->count());
            $this->assertSame(1, DerogationDevis::query()->where('devis_id', $devis['id'])->where('statut', DerogationDevis::ANNULEE)->count());
            $this->assertSame(1, DerogationDevis::query()->where('devis_id', $devis['id'])->where('statut', DerogationDevis::ACCEPTEE)->count());
        });
        $this->assertSame('non_retenu', (string) $this->enBase($devis['id'])->statut);
    }

    public function test_les_droits_de_creation_d_une_version(): void
    {
        $devis = $this->nouveauDevis();

        foreach (['Conducteur travaux', 'Administratif'] as $role) {
            $this->creerVersion($devis['id'], [], $this->creerUtilisateur($this->entreprise, $role))->assertForbidden();
        }

        // Une autre entreprise ne voit pas ce devis.
        $ailleurs = $this->creerEntreprise();
        $this->creerVersion($devis['id'], [], $this->creerUtilisateur($ailleurs, 'Direction'))->assertNotFound();

        $this->creerVersion($devis['id'], ['mode' => 'en_plus'], $this->direction)->assertCreated();
        $this->creerVersion($devis['id'], ['mode' => 'en_plus'])->assertCreated();
        $this->assertSame(3, $this->chez(fn () => Devis::query()->count()));
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $devis = $this->nouveauDevis();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/devis/{$devis['id']}/versions")->assertUnauthorized();
    }

    public function test_les_versions_suivent_la_visibilite_du_devis(): void
    {
        $devis = $this->nouveauDevis('prive');
        $autreCommercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        // Le devis privé d'un autre : introuvable.
        $this->creerVersion($devis['id'], [], $autreCommercial)->assertNotFound();

        // La Direction crée la version : elle reste dans le dossier du commercial.
        $versionId = (int) $this->creerVersion($devis['id'], [], $this->direction)->assertCreated()
            ->assertJsonPath('portee', 'prive')
            ->assertJsonPath('auteur_id', $this->commercial->id)
            ->json('id');

        $this->agirEn($this->commercial)->getJson("/api/devis/{$versionId}")->assertOk()->assertJsonCount(2, 'versions');
        $this->agirEn($autreCommercial)->getJson("/api/devis/{$versionId}")->assertNotFound();

        $this->assertSame(1, $this->chez(fn () => Activity::query()
            ->where('event', 'created')
            ->where('subject_id', $versionId)
            ->where('causer_id', $this->direction->id)
            ->where('properties->numero_source', $devis['numero'])
            ->count()));
    }

    public function test_la_fiche_et_la_liste_montrent_les_versions(): void
    {
        $devis = $this->nouveauDevis();
        $v2 = (int) $this->creerVersion($devis['id'], ['mode' => 'en_plus'])->assertCreated()->json('id');
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Martin']));
        $this->agirEn($this->commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Cuisine'])->assertCreated();

        $this->agirEn($this->commercial)->getJson("/api/devis/{$v2}")->assertOk()
            ->assertJsonPath('version.rang', 2)
            ->assertJsonPath('version.origine_id', $devis['id'])
            ->assertJsonPath('version.nombre_de_versions', 2)
            ->assertJsonPath('peut_creer_version', true)
            ->assertJsonCount(2, 'versions')
            ->assertJsonPath('versions.0.numero', $devis['numero'])
            ->assertJsonPath('versions.0.rang', 1)
            ->assertJsonPath('versions.0.courante', false)
            ->assertJsonPath('versions.1.numero', $devis['numero'] . '-V2')
            ->assertJsonPath('versions.1.statut_libelle', 'En préparation')
            ->assertJsonPath('versions.1.courante', true);

        // Un devis sans autre version : une seule entrée.
        $this->assertSame(3, $this->agirEn($this->commercial)->getJson('/api/devis')->assertOk()->json('total'));
        // Le filtre « groupe » rend toutes les versions d'un devis, depuis n'importe laquelle.
        foreach ([$devis['id'], $v2] as $id) {
            $this->assertSame(2, $this->agirEn($this->commercial)->getJson("/api/devis?groupe={$id}")->assertOk()->json('total'));
        }
    }

    public function test_le_statut_non_retenue_est_pose_par_l_application_seulement(): void
    {
        $config = DevisState::config();
        foreach (['en_preparation', 'en_attente_derogation', 'pret_a_envoyer', 'envoye', 'delivre', 'consulte', 'relance_1', 'relance_2', 'negociation'] as $ouvert) {
            $this->assertTrue($config->isTransitionAllowed($ouvert, 'non_retenu'), $ouvert);
        }
        foreach (['accepte', 'refuse', 'expire', 'annule'] as $decide) {
            $this->assertFalse($config->isTransitionAllowed($decide, 'non_retenu'), $decide);
            $this->assertFalse($config->isTransitionAllowed('non_retenu', $decide), $decide);
        }
        $this->assertFalse($config->isTransitionAllowed('non_retenu', 'en_preparation'));

        // Pas à la main.
        $devis = $this->nouveauDevis();
        $this->passerA($devis['id'], 'envoye');
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis['id']}/statut", ['statut' => 'non_retenu', 'motif' => 'Test'])
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
    }
}
