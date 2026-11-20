<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\Parametre;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot « paramètres du devis » (1re partie) : copie des réglages
 * de l'entreprise, taux horaires du devis, difficulté, TVA, code article,
 * prix forcé, suppression d'un brouillon.
 */
class DevisParametresTest extends TestCase
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
     * Devis créé par l'API (ses paramètres sont copiés à ce moment-là).
     *
     * @param array<string, mixed> $attributs
     * @return int
     */
    private function nouveauDevis(?User $auteur = null, array $attributs = []): int
    {
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));

        return $this->agirEn($auteur ?? $this->commercial)->postJson('/api/devis', array_merge([
            'client_id' => $client->id, 'titre' => 'Salle de bain',
        ], $attributs))->assertCreated()->json('id');
    }

    /**
     * Article : 1,5 h d'ouvrier et 2 fournitures à 8 EUR (achat 5), TVA 10 %.
     * Avec le taux de l'entreprise : vente 106, revient 70.
     *
     * @return int
     */
    private function article(string $reference = 'ART-P1'): int
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

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function ajouter(int $devis, int $article, float $quantite = 1)
    {
        return $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => $quantite]);
    }

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function regler(int $devis, array $donnees, ?User $auteur = null)
    {
        return $this->agirEn($auteur ?? $this->commercial)->putJson("/api/devis/{$devis}/parametres", $donnees);
    }

    public function test_le_devis_copie_les_parametres_de_l_entreprise_a_la_creation(): void
    {
        $this->chez(fn () => Parametre::ecrire('marge_fournitures_defaut', 30));
        $devis = $this->nouveauDevis();

        $reponse = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")
            ->assertOk()
            ->assertJsonPath('modifiable', true)
            ->assertJsonPath('parametres.tva_source', 'entreprise')
            ->assertJsonCount(1, 'taux_horaires')
            ->assertJsonPath('taux_horaires.0.code', 'ouvrier')
            ->assertJsonPath('taux_horaires.0.libelle', 'Ouvrier')
            ->assertJsonPath('taux_horaires.0.source', 'entreprise');

        $this->assertEqualsWithDelta(1, (float) $reponse->json('parametres.coefficient_difficulte'), 0.001);
        $this->assertEqualsWithDelta(1, (float) $reponse->json('parametres.coefficient_zone'), 0.001);
        $this->assertEqualsWithDelta(20, (float) $reponse->json('parametres.tva_defaut'), 0.001);
        $this->assertEqualsWithDelta(30, (float) $reponse->json('parametres.marge_fournitures'), 0.001);
        $this->assertEqualsWithDelta(60, (float) $reponse->json('taux_horaires.0.taux_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(40, (float) $reponse->json('taux_horaires.0.cout_revient_ht'), 0.001);
        $this->assertEqualsWithDelta(1.5, (float) $reponse->json('bornes.coefficient_difficulte.max'), 0.001);
    }

    public function test_a_coefficient_neutre_les_prix_sont_identiques_au_centime(): void
    {
        $devis = $this->nouveauDevis();
        $reponse = $this->ajouter($devis, $this->article(), 3)->assertCreated()
            ->assertJsonPath('code_article', 'ART-P1')
            ->assertJsonPath('prix_force', false)
            ->assertJsonPath('composants.0.qualification', 'ouvrier');

        $this->assertEqualsWithDelta(106, (float) $reponse->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(70, (float) $reponse->json('prix_revient_unitaire'), 0.001);
        $this->assertEqualsWithDelta(1.5, (float) $reponse->json('composants.0.quantite'), 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) $reponse->json('composants.0.quantite_base'), 0.0001);
        $this->assertEqualsWithDelta(318, (float) $reponse->json('totaux_devis.total_ht'), 0.001);

        $effet = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->json('effet_difficulte');
        $this->assertEqualsWithDelta(0, (float) $effet['heures'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $effet['vente_ht'], 0.001);
    }

    public function test_un_changement_de_taux_de_l_entreprise_ne_touche_pas_un_devis_existant(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->article();
        $this->chez(fn () => TauxHoraire::query()->where('code', 'ouvrier')->update(['taux_vente_ht' => 80, 'cout_revient_ht' => 55]));

        // Le devis existant garde son taux de 60 ; un nouveau devis prend 80.
        $this->assertEqualsWithDelta(106, (float) $this->ajouter($devis, $article)->assertCreated()->json('prix_unitaire_ht'), 0.001);

        $nouveau = $this->nouveauDevis();
        $reponse = $this->ajouter($nouveau, $article)->assertCreated();
        $this->assertEqualsWithDelta(136, (float) $reponse->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(92.5, (float) $reponse->json('prix_revient_unitaire'), 0.001);
    }

    public function test_le_taux_horaire_s_ajuste_pour_ce_devis_seulement(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->article();
        $avant = $this->ajouter($devis, $article)->assertCreated()->json('id');

        $reponse = $this->regler($devis, ['taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 70]]])
            ->assertOk()
            ->assertJsonPath('taux_horaires.0.source', 'modifie');
        $this->assertEqualsWithDelta(70, (float) $reponse->json('taux_horaires.0.taux_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(60, (float) $reponse->json('taux_horaires.0.taux_vente_entreprise'), 0.001);

        // Nouvelle ligne : 1,5 h x 70 + 16 = 121 ; le revient ne bouge pas (70).
        $apres = $this->ajouter($devis, $article)->assertCreated();
        $this->assertEqualsWithDelta(121, (float) $apres->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(70, (float) $apres->json('prix_revient_unitaire'), 0.001);
        $this->assertEqualsWithDelta(70, (float) $apres->json('composants.0.taux_horaire_vente'), 0.001);

        // La ligne déjà écrite et l'entreprise ne changent pas.
        $this->assertEqualsWithDelta(106, (float) $this->chez(fn () => LigneDevis::query()->findOrFail($avant)->prix_unitaire_ht), 0.001);
        $this->assertEqualsWithDelta(60, (float) $this->chez(fn () => TauxHoraire::query()->where('code', 'ouvrier')->value('taux_vente_ht')), 0.001);

        // Revenir au taux de l'entreprise remet la source.
        $this->regler($devis, ['taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 60]]])
            ->assertOk()->assertJsonPath('taux_horaires.0.source', 'entreprise');
    }

    public function test_la_difficulte_multiplie_le_temps_de_main_d_oeuvre(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->article();
        $avant = $this->ajouter($devis, $article)->assertCreated()->json('id');

        $this->regler($devis, ['coefficient_difficulte' => 1.2])->assertOk();

        // 1,5 h x 1,2 = 1,8 h : vente 108 + 16 = 124 ; revient 72 + 10 = 82.
        $reponse = $this->ajouter($devis, $article, 2)->assertCreated()->assertJsonPath('prix_force', false);
        $this->assertEqualsWithDelta(124, (float) $reponse->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(82, (float) $reponse->json('prix_revient_unitaire'), 0.001);
        $this->assertEqualsWithDelta(1.8, (float) $reponse->json('composants.0.quantite'), 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) $reponse->json('composants.0.quantite_base'), 0.0001);
        $this->assertEqualsWithDelta(2, (float) $reponse->json('composants.1.quantite'), 0.0001);

        // La ligne déjà écrite ne bouge pas.
        $this->assertEqualsWithDelta(106, (float) $this->chez(fn () => LigneDevis::query()->findOrFail($avant)->prix_unitaire_ht), 0.001);

        // Effet visible : 0,3 h x 2 = 0,6 h et 0,3 x 60 x 2 = 36 EUR.
        $parametres = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->assertOk();
        $this->assertEqualsWithDelta(1.2, (float) $parametres->json('parametres.coefficient_difficulte'), 0.001);
        $this->assertEqualsWithDelta(0.6, (float) $parametres->json('effet_difficulte.heures'), 0.001);
        $this->assertEqualsWithDelta(36, (float) $parametres->json('effet_difficulte.vente_ht'), 0.001);
    }

    public function test_les_valeurs_hors_bornes_sont_refusees(): void
    {
        $devis = $this->nouveauDevis();

        foreach ([0.95, 1.55, 1.23] as $valeur) {
            $this->regler($devis, ['coefficient_difficulte' => $valeur])
                ->assertUnprocessable()->assertJsonValidationErrors('coefficient_difficulte');
        }
        $this->regler($devis, ['coefficient_difficulte' => 1.5])->assertOk();
        $this->regler($devis, ['coefficient_difficulte' => 1.05])->assertOk();

        // Zone : préparée, pas encore activée.
        $this->regler($devis, ['coefficient_zone' => 1.1])
            ->assertUnprocessable()->assertJsonValidationErrors('coefficient_zone');
        $this->regler($devis, ['coefficient_zone' => 1])->assertOk();

        $this->regler($devis, ['tva_defaut' => 120])
            ->assertUnprocessable()->assertJsonValidationErrors('tva_defaut');
        $this->regler($devis, ['taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('taux_horaires.0.taux_vente_ht');
        $this->regler($devis, ['taux_horaires' => [['code' => 'inconnu', 'taux_vente_ht' => 50]]])
            ->assertUnprocessable()->assertJsonValidationErrors('taux_horaires.0.code');

        $reponse = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres");
        $this->assertEqualsWithDelta(1, (float) $reponse->json('parametres.coefficient_zone'), 0.001);
        $this->assertEqualsWithDelta(60, (float) $reponse->json('taux_horaires.0.taux_vente_ht'), 0.001);
    }

    public function test_la_tva_du_devis_s_applique_aux_nouvelles_lignes(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->article();

        // TVA de l'entreprise : la ligne libre prend 20 %, la ligne d'article garde celle de l'article (10 %).
        $libre = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes", [
            'libelle' => 'Travaux', 'quantite' => 1, 'prix_unitaire_ht' => 100,
        ])->assertCreated();
        $this->assertEqualsWithDelta(20, (float) $libre->json('taux_tva'), 0.001);
        $this->assertEqualsWithDelta(10, (float) $this->ajouter($devis, $article)->assertCreated()->json('taux_tva'), 0.001);

        // TVA réglée à la main sur le devis : elle prime, y compris sur l'article.
        $this->regler($devis, ['tva_defaut' => 5.5])->assertOk()->assertJsonPath('parametres.tva_source', 'modifie');
        $libre = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes", [
            'libelle' => 'Autres travaux', 'quantite' => 1, 'prix_unitaire_ht' => 100,
        ])->assertCreated();
        $this->assertEqualsWithDelta(5.5, (float) $libre->json('taux_tva'), 0.001);
        $this->assertEqualsWithDelta(5.5, (float) $this->ajouter($devis, $article)->assertCreated()->json('taux_tva'), 0.001);

        // Une TVA donnée sur la ligne reste prioritaire.
        $choisie = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes", [
            'libelle' => 'Fourniture', 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20,
        ])->assertCreated();
        $this->assertEqualsWithDelta(20, (float) $choisie->json('taux_tva'), 0.001);
    }

    public function test_la_tva_par_defaut_de_l_entreprise_est_un_reglage(): void
    {
        $this->chez(fn () => Parametre::ecrire('tva_defaut', 10));
        $devis = $this->nouveauDevis();

        $reponse = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->assertOk();
        $this->assertEqualsWithDelta(10, (float) $reponse->json('parametres.tva_defaut'), 0.001);

        $libre = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes", [
            'libelle' => 'Travaux', 'quantite' => 1, 'prix_unitaire_ht' => 100,
        ])->assertCreated();
        $this->assertEqualsWithDelta(10, (float) $libre->json('taux_tva'), 0.001);
    }

    public function test_un_prix_change_a_la_main_marque_la_ligne_prix_force(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article())->assertCreated()->json('id');

        // Changer la quantité ne force pas le prix.
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligne}", ['quantite' => 4])
            ->assertOk()->assertJsonPath('prix_force', false);
        // Renvoyer le même prix non plus.
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligne}", ['prix_unitaire_ht' => 106])
            ->assertOk()->assertJsonPath('prix_force', false);
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligne}", ['prix_unitaire_ht' => 110])
            ->assertOk()->assertJsonPath('prix_force', true);

        // Un article à prix forcé donne une ligne à prix forcé.
        $force = $this->article('ART-FORCE');
        DB::table('articles_prix')->where('id', $force)->update(['prix_force_ht' => 150]);
        $reponse = $this->ajouter($devis, $force)->assertCreated()->assertJsonPath('prix_force', true);
        $this->assertEqualsWithDelta(150, (float) $reponse->json('prix_unitaire_ht'), 0.001);

        // Une ligne libre n'est jamais « forcée ».
        $libre = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes", [
            'libelle' => 'Travaux', 'quantite' => 1, 'prix_unitaire_ht' => 100,
        ])->assertCreated()->assertJsonPath('prix_force', false)->json('id');
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$libre}", ['prix_unitaire_ht' => 120])
            ->assertOk()->assertJsonPath('prix_force', false)->assertJsonPath('code_article', null);
    }

    public function test_l_avenant_herite_des_parametres_du_devis_d_origine(): void
    {
        $devis = $this->nouveauDevis();
        $this->regler($devis, [
            'coefficient_difficulte' => 1.2,
            'tva_defaut' => 10,
            'taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 70]],
        ])->assertOk();
        $this->chez(fn () => Devis::query()->findOrFail($devis)->forceFill(['statut' => 'accepte'])->save());
        $this->chez(fn () => TauxHoraire::query()->where('code', 'ouvrier')->update(['taux_vente_ht' => 90]));

        $avenant = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/avenants")->assertCreated()->json('id');

        $reponse = $this->agirEn($this->commercial)->getJson("/api/devis/{$avenant}/parametres")
            ->assertOk()
            ->assertJsonPath('modifiable', true)
            ->assertJsonPath('parametres.tva_source', 'modifie')
            ->assertJsonPath('taux_horaires.0.source', 'modifie');
        $this->assertEqualsWithDelta(1.2, (float) $reponse->json('parametres.coefficient_difficulte'), 0.001);
        $this->assertEqualsWithDelta(10, (float) $reponse->json('parametres.tva_defaut'), 0.001);
        $this->assertEqualsWithDelta(70, (float) $reponse->json('taux_horaires.0.taux_vente_ht'), 0.001);
    }

    public function test_un_devis_fige_ne_se_regle_plus(): void
    {
        $devis = $this->nouveauDevis();
        $this->chez(fn () => Devis::query()->findOrFail($devis)->forceFill(['statut' => 'envoye'])->save());

        $this->regler($devis, ['coefficient_difficulte' => 1.2])
            ->assertUnprocessable()->assertJsonValidationErrors('devis');
        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")
            ->assertOk()->assertJsonPath('modifiable', false);

        // Un devis envoyé avant ce lot n'a pas de paramètres : on ne lui en invente pas.
        $ancien = $this->chez(function (): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Martin']);
            $devis = Devis::create(['client_id' => $client->id, 'titre' => 'Ancien', 'auteur_id' => $this->commercial->id, 'portee' => 'entreprise']);
            $devis->forceFill(['statut' => 'envoye'])->save();

            return $devis;
        });
        $this->agirEn($this->commercial)->getJson("/api/devis/{$ancien->id}/parametres")
            ->assertOk()
            ->assertJsonPath('parametres', null)
            ->assertJsonPath('modifiable', false)
            ->assertJsonCount(0, 'taux_horaires');
    }

    public function test_un_brouillon_ancien_recoit_ses_parametres_a_la_premiere_ouverture(): void
    {
        $ancien = $this->chez(function (): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Martin']);

            return Devis::create(['client_id' => $client->id, 'titre' => 'Brouillon', 'auteur_id' => $this->commercial->id, 'portee' => 'entreprise']);
        });

        $this->agirEn($this->commercial)->getJson("/api/devis/{$ancien->id}/parametres")
            ->assertOk()
            ->assertJsonPath('modifiable', true)
            ->assertJsonPath('taux_horaires.0.code', 'ouvrier');
    }

    public function test_les_droits_sur_les_parametres(): void
    {
        $devis = $this->nouveauDevis(null, ['portee' => 'entreprise']);
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');

        // Lecture avec devis.voir ; modification réservée à devis.modifier_parametres.
        $this->agirEn($conducteur)->getJson("/api/devis/{$devis}/parametres")->assertOk();
        $this->regler($devis, ['coefficient_difficulte' => 1.2], $conducteur)->assertForbidden();
        $this->regler($devis, ['coefficient_difficulte' => 1.2], $this->direction)->assertOk();

        // Sans devis.voir_marge : ni coût de revient ni marge des fournitures.
        Role::firstOrCreate(['name' => 'Sans marge', 'guard_name' => 'web'])->syncPermissions(['devis.voir']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $sansMarge = $this->creerUtilisateur($this->entreprise, 'Sans marge');
        $this->agirEn($sansMarge)->getJson("/api/devis/{$devis}/parametres")
            ->assertOk()
            ->assertJsonPath('taux_horaires.0.code', 'ouvrier')
            ->assertJsonMissingPath('taux_horaires.0.cout_revient_ht')
            ->assertJsonMissingPath('parametres.marge_fournitures');

        // Un devis privé d'un autre reste introuvable.
        $prive = $this->nouveauDevis();
        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $this->agirEn($collegue)->getJson("/api/devis/{$prive}/parametres")->assertNotFound();
        $this->regler($prive, ['coefficient_difficulte' => 1.2], $collegue)->assertNotFound();
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->getJson('/api/devis/1/parametres')->assertUnauthorized();
        $this->putJson('/api/devis/1/parametres', ['coefficient_difficulte' => 1.2])->assertUnauthorized();
    }

    public function test_les_parametres_d_une_autre_entreprise_sont_introuvables(): void
    {
        $autre = $this->creerEntreprise();
        $etranger = $this->dansEntreprise($autre, function (): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Pirate']);

            return Devis::create(['client_id' => $client->id, 'titre' => 'Secret', 'portee' => 'entreprise']);
        });

        $this->agirEn($this->direction)->getJson("/api/devis/{$etranger->id}/parametres")->assertNotFound();
        $this->regler($etranger->id, ['coefficient_difficulte' => 1.2], $this->direction)->assertNotFound();
    }

    public function test_une_modification_est_journalisee(): void
    {
        $devis = $this->nouveauDevis();

        // Rien ne change : rien au journal.
        $this->regler($devis, ['coefficient_difficulte' => 1])->assertOk();
        $this->assertSame(0, $this->chez(fn () => Activity::query()->where('event', 'parametres_devis_modifies')->count()));

        $this->regler($devis, [
            'coefficient_difficulte' => 1.1,
            'taux_horaires' => [['code' => 'ouvrier', 'taux_vente_ht' => 65]],
        ])->assertOk();

        $trace = $this->chez(fn () => Activity::query()->where('event', 'parametres_devis_modifies')->latest('id')->firstOrFail());
        $this->assertSame($devis, (int) $trace->subject_id);
        $this->assertSame($this->commercial->id, (int) $trace->causer_id);
        $this->assertEqualsWithDelta(1, (float) $trace->properties['avant']['coefficient_difficulte'], 0.001);
        $this->assertEqualsWithDelta(1.1, (float) $trace->properties['apres']['coefficient_difficulte'], 0.001);
        $this->assertEqualsWithDelta(60, (float) $trace->properties['avant']['taux_horaires']['ouvrier'], 0.001);
        $this->assertEqualsWithDelta(65, (float) $trace->properties['apres']['taux_horaires']['ouvrier'], 0.001);
    }

    public function test_un_brouillon_se_supprime_par_son_auteur_ou_la_direction(): void
    {
        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $premier = $this->nouveauDevis(null, ['portee' => 'entreprise']);
        $second = $this->nouveauDevis(null, ['portee' => 'entreprise']);

        // Un collègue voit le devis d'entreprise mais ne peut pas le supprimer.
        $this->agirEn($collegue)->deleteJson("/api/devis/{$premier}")->assertForbidden();
        $this->assertNotNull($this->chez(fn () => Devis::query()->find($premier)));

        $this->agirEn($this->commercial)->deleteJson("/api/devis/{$premier}")->assertNoContent();
        $this->agirEn($this->direction)->deleteJson("/api/devis/{$second}")->assertNoContent();
        $this->assertSame(0, $this->chez(fn () => Devis::query()->count()));

        // Un devis envoyé ne se supprime pas, même par la Direction.
        $envoye = $this->nouveauDevis(null, ['portee' => 'entreprise']);
        $this->chez(fn () => Devis::query()->findOrFail($envoye)->forceFill(['statut' => 'envoye'])->save());
        $this->agirEn($this->direction)->deleteJson("/api/devis/{$envoye}")->assertUnprocessable();
    }
}
