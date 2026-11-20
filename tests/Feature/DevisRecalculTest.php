<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot « paramètres du devis » (2e partie) : « Recalculer le
 * devis » et « Appliquer la TVA à toutes les lignes ».
 */
class DevisRecalculTest extends TestCase
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
        $this->chez(fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60,
            'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
        ]));
    }

    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    /**
     * @param array<string, mixed> $attributs
     * @return int
     */
    private function nouveauDevis(array $attributs = []): int
    {
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));

        return $this->agirEn($this->commercial)->postJson('/api/devis', array_merge([
            'client_id' => $client->id, 'titre' => 'Salle de bain',
        ], $attributs))->assertCreated()->json('id');
    }

    /**
     * Article : 1,5 h d'ouvrier et 2 fournitures à 8 EUR (achat 5), TVA 10 %.
     * Au taux de 60 : vente 106, revient 70.
     *
     * @return int
     */
    private function article(string $reference = 'ART-P2'): int
    {
        $id = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => $reference, 'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');

        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5,
        ])->assertCreated();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 2,
            'unite' => 'u', 'prix_achat_ht' => 5, 'prix_vente_ht' => 8,
        ])->assertCreated();

        return $id;
    }

    private function ajouter(int $devis, int $article, float $quantite = 1): int
    {
        return $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => $quantite])
            ->assertCreated()->json('id');
    }

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function regler(int $devis, array $donnees)
    {
        return $this->agirEn($this->commercial)->putJson("/api/devis/{$devis}/parametres", $donnees)->assertOk();
    }

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function recalculer(int $devis, array $donnees = [], ?User $auteur = null)
    {
        return $this->agirEn($auteur ?? $this->commercial)->postJson("/api/devis/{$devis}/recalculer", $donnees);
    }

    private function ligne(int $id): LigneDevis
    {
        return $this->chez(fn () => LigneDevis::query()->with('composants')->findOrFail($id));
    }

    public function test_la_simulation_compte_sans_rien_ecrire(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article(), 2);
        $this->regler($devis, ['coefficient_difficulte' => 1.2, 'taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 70]]]);

        // 1,8 h x 70 = 126 + 16 = 142 l'unité, soit 284 pour 2.
        $reponse = $this->recalculer($devis, ['simulation' => true])
            ->assertOk()
            ->assertJsonPath('simulation', true)
            ->assertJsonPath('lignes_recalculees', 1)
            ->assertJsonPath('lignes_modifiees', 1)
            ->assertJsonPath('lignes_prix_force_conservees', 0)
            ->assertJsonPath('message', '1 ligne recalculée.');
        $this->assertEqualsWithDelta(212, (float) $reponse->json('total_ht_avant'), 0.001);
        $this->assertEqualsWithDelta(284, (float) $reponse->json('total_ht_apres'), 0.001);
        $this->assertEqualsWithDelta(212, (float) $reponse->json('totaux_devis.total_ht'), 0.001);

        // Rien n'a bougé en base, rien au journal.
        $this->assertEqualsWithDelta(106, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(1.5, (float) $this->ligne($ligne)->composants[0]->quantite, 0.0001);
        $this->assertEqualsWithDelta(212, (float) $this->chez(fn () => Devis::query()->findOrFail($devis)->total_ht), 0.001);
        $this->assertSame(0, $this->chez(fn () => Activity::query()->where('event', 'devis_recalcule')->count()));
    }

    public function test_le_recalcul_applique_les_parametres_aux_lignes_existantes(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article(), 2);
        $this->regler($devis, ['coefficient_difficulte' => 1.2, 'taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 70]]]);

        $reponse = $this->recalculer($devis)
            ->assertOk()
            ->assertJsonPath('simulation', false)
            ->assertJsonPath('lignes_recalculees', 1);
        $this->assertEqualsWithDelta(284, (float) $reponse->json('totaux_devis.total_ht'), 0.001);

        // Vente 142 ; revient 1,8 h x 40 + 10 = 82.
        $apres = $this->ligne($ligne);
        $this->assertEqualsWithDelta(142, (float) $apres->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(82, (float) $apres->prix_revient_unitaire, 0.001);
        $this->assertFalse((bool) $apres->prix_force);
        $this->assertEqualsWithDelta(1.8, (float) $apres->composants[0]->quantite, 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) $apres->composants[0]->quantite_base, 0.0001);
        $this->assertEqualsWithDelta(70, (float) $apres->composants[0]->taux_horaire_vente, 0.001);
        $this->assertEqualsWithDelta(8, (float) $apres->composants[1]->prix_vente_ht, 0.001);

        $trace = $this->chez(fn () => Activity::query()->where('event', 'devis_recalcule')->firstOrFail());
        $this->assertSame($devis, (int) $trace->subject_id);
        $this->assertSame(1, (int) $trace->properties['lignes_recalculees']);

        // Un second recalcul ne change plus rien.
        $this->recalculer($devis)->assertOk()->assertJsonPath('lignes_recalculees', 1)->assertJsonPath('lignes_modifiees', 0);
    }

    public function test_revenir_aux_parametres_neutres_redonne_les_prix_d_origine(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article(), 3);

        $this->regler($devis, ['coefficient_difficulte' => 1.35]);
        $this->recalculer($devis)->assertOk();
        $this->assertEqualsWithDelta(2.025, (float) $this->ligne($ligne)->composants[0]->quantite, 0.0001);

        $this->regler($devis, ['coefficient_difficulte' => 1]);
        $reponse = $this->recalculer($devis)->assertOk();

        $apres = $this->ligne($ligne);
        $this->assertEqualsWithDelta(106, (float) $apres->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(70, (float) $apres->prix_revient_unitaire, 0.001);
        $this->assertEqualsWithDelta(1.5, (float) $apres->composants[0]->quantite, 0.0001);
        $this->assertEqualsWithDelta(318, (float) $reponse->json('totaux_devis.total_ht'), 0.001);
    }

    public function test_les_prix_forces_sont_conserves_sauf_demande_contraire(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->article();
        $normale = $this->ajouter($devis, $article);
        $forcee = $this->ajouter($devis, $article);
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$forcee}", ['prix_unitaire_ht' => 110])
            ->assertOk()->assertJsonPath('prix_force', true);
        $this->regler($devis, ['taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 70]]]);

        // Par défaut : la ligne forcée ne bouge pas (prix, sous-détail, marque).
        $this->recalculer($devis)
            ->assertOk()
            ->assertJsonPath('lignes_recalculees', 1)
            ->assertJsonPath('lignes_prix_force_conservees', 1)
            ->assertJsonPath('message', '1 ligne recalculée, 1 ligne à prix forcé conservée.');
        $this->assertEqualsWithDelta(121, (float) $this->ligne($normale)->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(110, (float) $this->ligne($forcee)->prix_unitaire_ht, 0.001);
        $this->assertTrue((bool) $this->ligne($forcee)->prix_force);
        $this->assertEqualsWithDelta(60, (float) $this->ligne($forcee)->composants[0]->taux_horaire_vente, 0.001);

        // Sur demande : tout est remis à plat, la marque tombe.
        $this->recalculer($devis, ['inclure_prix_forces' => true])
            ->assertOk()
            ->assertJsonPath('lignes_recalculees', 2)
            ->assertJsonPath('lignes_prix_force_conservees', 0)
            ->assertJsonPath('message', '2 lignes recalculées.');
        $this->assertEqualsWithDelta(121, (float) $this->ligne($forcee)->prix_unitaire_ht, 0.001);
        $this->assertFalse((bool) $this->ligne($forcee)->prix_force);
    }

    public function test_une_ligne_libre_n_est_jamais_recalculee(): void
    {
        $devis = $this->nouveauDevis();
        $libre = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes", [
            'libelle' => 'Travaux', 'quantite' => 2, 'prix_unitaire_ht' => 100, 'prix_revient_unitaire' => 60,
        ])->assertCreated()->json('id');
        $this->regler($devis, ['coefficient_difficulte' => 1.5, 'taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 90]]]);

        $this->recalculer($devis, ['inclure_prix_forces' => true])
            ->assertOk()
            ->assertJsonPath('lignes_recalculees', 0)
            ->assertJsonPath('lignes_sans_sous_detail', 1)
            ->assertJsonPath('message', '0 lignes recalculées.');
        $this->assertEqualsWithDelta(100, (float) $this->ligne($libre)->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(60, (float) $this->ligne($libre)->prix_revient_unitaire, 0.001);
    }

    public function test_une_ligne_d_avant_le_lot_se_recalcule_par_le_libelle_du_taux(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article());
        DB::table('lignes_devis_composants')->where('ligne_devis_id', $ligne)->update(['quantite_base' => null, 'qualification' => null]);
        $this->regler($devis, ['coefficient_difficulte' => 1.2, 'taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 70]]]);

        $this->recalculer($devis)->assertOk()->assertJsonPath('lignes_recalculees', 1);

        $apres = $this->ligne($ligne);
        $this->assertEqualsWithDelta(142, (float) $apres->prix_unitaire_ht, 0.001);
        $this->assertSame('ouvrier', $apres->composants[0]->qualification);
        $this->assertEqualsWithDelta(1.5, (float) $apres->composants[0]->quantite_base, 0.0001);
    }

    public function test_le_recalcul_passe_par_le_controle_des_seuils(): void
    {
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_globale_min', ['valeur' => 20])->assertOk();
        $devis = $this->nouveauDevis(['portee' => 'entreprise']);
        $ligne = $this->ajouter($devis, $this->article());

        // Taux baissé à 30 : vente 61 pour un revient de 70. Régler est permis, recalculer non.
        $this->regler($devis, ['taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 30]]]);
        $this->recalculer($devis)
            ->assertUnprocessable()
            ->assertJsonPath('motif', 'marge_sous_le_seuil');
        $this->assertEqualsWithDelta(106, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(60, (float) $this->ligne($ligne)->composants[0]->taux_horaire_vente, 0.001);
        $this->assertSame(0, $this->chez(fn () => Activity::query()->where('event', 'devis_recalcule')->count()));

        // La simulation répond toujours : elle n'écrit rien.
        $this->recalculer($devis, ['simulation' => true])->assertOk()->assertJsonPath('lignes_recalculees', 1);

        // La Direction n'est pas bloquée.
        $this->recalculer($devis, [], $this->direction)->assertOk();
        $this->assertEqualsWithDelta(61, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);
    }

    public function test_la_tva_du_devis_s_applique_a_toutes_les_lignes(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->ajouter($devis, $this->article());
        $libre = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes", [
            'libelle' => 'Travaux', 'quantite' => 1, 'prix_unitaire_ht' => 100,
        ])->assertCreated()->json('id');
        $this->regler($devis, ['tva_defaut' => 5.5]);

        // 206 HT à 5,5 % : 11,33 de TVA.
        $reponse = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/parametres/appliquer-tva")
            ->assertOk()
            ->assertJsonPath('lignes_modifiees', 2);
        $this->assertEqualsWithDelta(5.5, (float) $reponse->json('taux_tva'), 0.001);
        $this->assertEqualsWithDelta(206, (float) $reponse->json('totaux_devis.total_ht'), 0.001);
        $this->assertEqualsWithDelta(11.33, (float) $reponse->json('totaux_devis.total_tva'), 0.001);
        $this->assertEqualsWithDelta(5.5, (float) $this->ligne($article)->taux_tva, 0.001);
        $this->assertEqualsWithDelta(5.5, (float) $this->ligne($libre)->taux_tva, 0.001);
        $this->assertSame(1, $this->chez(fn () => Activity::query()->where('event', 'tva_appliquee')->count()));

        // Une seconde fois : rien à changer, rien au journal.
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/parametres/appliquer-tva")
            ->assertOk()->assertJsonPath('lignes_modifiees', 0);
        $this->assertSame(1, $this->chez(fn () => Activity::query()->where('event', 'tva_appliquee')->count()));
    }

    public function test_les_droits_et_les_devis_figes(): void
    {
        $devis = $this->nouveauDevis(['portee' => 'entreprise']);
        $this->ajouter($devis, $this->article());
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->recalculer($devis, [], $conducteur)->assertForbidden();
        $this->agirEn($conducteur)->postJson("/api/devis/{$devis}/parametres/appliquer-tva")->assertForbidden();
        $this->recalculer($devis, ['simulation' => 'peut-etre'])->assertUnprocessable()->assertJsonValidationErrors('simulation');

        // Un devis privé d'un autre est introuvable.
        $prive = $this->nouveauDevis();
        $this->recalculer($prive, [], $collegue)->assertNotFound();
        $this->agirEn($collegue)->postJson("/api/devis/{$prive}/parametres/appliquer-tva")->assertNotFound();

        // Un devis envoyé ne se recalcule plus.
        $this->chez(fn () => Devis::query()->findOrFail($devis)->forceFill(['statut' => 'envoye'])->save());
        $this->recalculer($devis)->assertUnprocessable()->assertJsonValidationErrors('devis');
        $this->recalculer($devis, ['simulation' => true])->assertUnprocessable()->assertJsonValidationErrors('devis');
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/parametres/appliquer-tva")
            ->assertUnprocessable()->assertJsonValidationErrors('devis');
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->postJson('/api/devis/1/recalculer')->assertUnauthorized();
        $this->postJson('/api/devis/1/parametres/appliquer-tva')->assertUnauthorized();
    }

    public function test_le_devis_d_une_autre_entreprise_est_introuvable(): void
    {
        $autre = $this->creerEntreprise();
        $etranger = $this->dansEntreprise($autre, function (): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Pirate']);

            return Devis::create(['client_id' => $client->id, 'titre' => 'Secret', 'portee' => 'entreprise']);
        });

        $this->recalculer($etranger->id, [], $this->direction)->assertNotFound();
        $this->agirEn($this->direction)->postJson("/api/devis/{$etranger->id}/parametres/appliquer-tva")->assertNotFound();
    }
}
