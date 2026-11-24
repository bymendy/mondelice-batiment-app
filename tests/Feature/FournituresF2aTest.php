<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Parametre;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tâche 3.6 bis, lot F2a : frais généraux sur fournitures, revient, recalcul
 * des fournitures à prix calculé, indicateur « sous le seuil ».
 */
class FournituresF2aTest extends TestCase
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

    private function reglage(string $cle, mixed $valeur): void
    {
        $this->chez(fn () => Parametre::ecrire($cle, $valeur));
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function fourniture(array $donnees): int
    {
        return $this->agirEn($this->direction)
            ->postJson('/api/fournitures', $donnees + ['designation' => 'Carrelage', 'unite' => 'm2'])
            ->assertCreated()->json('id');
    }

    private function venteFourniture(int $id): float
    {
        return (float) $this->agirEn($this->direction)->getJson("/api/fournitures/{$id}")->assertOk()->json('prix_vente_ht');
    }

    /**
     * Article : 1,5 h d'ouvrier (vente 90, revient 60) et une fourniture de la base.
     */
    private function article(?int $fourniture = null, float $quantite = 2): int
    {
        $id = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-F2-' . uniqid(), 'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');

        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5,
        ])->assertCreated();

        if ($fourniture !== null) {
            $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
                'type' => 'fourniture_base', 'fourniture_id' => $fourniture, 'quantite' => $quantite,
            ])->assertCreated();
        }

        return $id;
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function lire(int $article, ?User $qui = null)
    {
        return $this->agirEn($qui ?? $this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk();
    }

    public function test_les_frais_generaux_sont_un_reglage_de_l_entreprise(): void
    {
        $this->agirEn($this->direction)->putJson('/api/parametres/frais_generaux_fournitures', ['valeur' => 5])->assertOk();
        $this->assertEqualsWithDelta(5, (float) $this->chez(fn () => Parametre::lire('frais_generaux_fournitures')), 0.001);

        $this->agirEn($this->direction)->putJson('/api/parametres/frais_generaux_fournitures', ['valeur' => 120])->assertUnprocessable();
        $this->agirEn($this->commercial)->putJson('/api/parametres/frais_generaux_fournitures', ['valeur' => 8])->assertForbidden();
        $this->assertEqualsWithDelta(5, (float) $this->chez(fn () => Parametre::lire('frais_generaux_fournitures')), 0.001);
    }

    public function test_le_prix_de_vente_calcule_suit_la_formule(): void
    {
        // Sans frais généraux : 100 / 0,9 = 111,1111.
        $this->reglage('marge_fournitures_defaut', 10);
        $sans = $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'designation' => 'Sans frais', 'unite' => 'u', 'prix_achat_ht' => 100,
        ])->assertCreated();
        $this->assertEqualsWithDelta(111.1111, (float) $sans->json('prix_vente_ht'), 0.0001);

        // Exemple du 12 novembre : achat 100, frais 5 %, marge 10 % -> revient 105, vente 116,67.
        $this->reglage('frais_generaux_fournitures', 5);
        $avec = $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'designation' => 'Avec frais', 'unite' => 'u', 'prix_achat_ht' => 100,
        ])->assertCreated()->assertJsonPath('prix_vente_saisi', false);
        $this->assertEqualsWithDelta(116.6667, (float) $avec->json('prix_vente_ht'), 0.0001);

        // Même formule pour une ligne libre d'article dont on ne donne que le prix d'achat.
        $article = $this->article();
        $ligne = $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 1, 'unite' => 'u', 'prix_achat_ht' => 100,
        ])->assertCreated();
        $this->assertEqualsWithDelta(116.6667, (float) $ligne->json('composant.prix_vente_ht'), 0.0001);

        // Un prix de vente saisi n'est pas touché par les frais généraux.
        $saisi = $this->fourniture(['prix_achat_ht' => 100, 'prix_vente_ht' => 150]);
        $this->assertEqualsWithDelta(150, $this->venteFourniture($saisi), 0.0001);
    }

    public function test_le_revient_des_fournitures_inclut_les_frais_generaux(): void
    {
        $fourniture = $this->fourniture(['prix_achat_ht' => 100, 'prix_vente_ht' => 150]);
        $article = $this->article($fourniture);
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'materiel', 'designation' => 'Location', 'quantite' => 1, 'unite' => 'j', 'prix_achat_ht' => 10, 'prix_vente_ht' => 20,
        ])->assertCreated();

        // Sans frais généraux : revient 60 + 200 + 10 = 270 ; vente 90 + 300 + 20 = 410.
        $vue = $this->lire($article);
        $this->assertEqualsWithDelta(410, (float) $vue->json('prix_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(270, (float) $vue->json('prix_revient_ht'), 0.001);

        // Avec 5 % : seules les fournitures montent (2 x 105). Main-d'œuvre et matériel ne bougent pas.
        $this->reglage('frais_generaux_fournitures', 5);
        $vue = $this->lire($article);
        $this->assertEqualsWithDelta(410, (float) $vue->json('prix_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(280, (float) $vue->json('prix_revient_ht'), 0.001);
        $this->assertEqualsWithDelta(130, (float) $vue->json('marge_ht'), 0.001);

        $lignes = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}/composants")->assertOk();
        $this->assertEqualsWithDelta(60, (float) $lignes->json('data.0.total_revient_ht'), 0.001);
        $this->assertEqualsWithDelta(210, (float) $lignes->json('data.1.total_revient_ht'), 0.001);
        $this->assertEqualsWithDelta(100, (float) $lignes->json('data.1.prix_achat_ht'), 0.0001);
        $this->assertEqualsWithDelta(10, (float) $lignes->json('data.2.total_revient_ht'), 0.001);
    }

    public function test_un_changement_de_reglage_recalcule_les_fournitures_a_prix_calcule(): void
    {
        $this->reglage('marge_fournitures_defaut', 10);
        $calculee = $this->fourniture(['prix_achat_ht' => 100]);
        $saisie = $this->fourniture(['designation' => 'Plinthe', 'prix_achat_ht' => 100, 'prix_vente_ht' => 150]);
        $article = $this->article($calculee);
        // 90 + 2 x 111,1111 = 312,22.
        $this->assertEqualsWithDelta(312.22, (float) $this->lire($article)->json('prix_vente_ht'), 0.001);
        $this->assertSame(0, $this->chez(fn () => Activity::query()->where('event', 'fournitures_recalculees')->count()));

        // Frais généraux à 5 % : 116,6667 ; l'article suit (90 + 233,33).
        $this->reglage('frais_generaux_fournitures', 5);
        $this->assertEqualsWithDelta(116.6667, $this->venteFourniture($calculee), 0.0001);
        $this->assertEqualsWithDelta(150, $this->venteFourniture($saisie), 0.0001);
        $this->assertEqualsWithDelta(323.33, (float) $this->lire($article)->json('prix_vente_ht'), 0.001);

        // Marge par défaut à 20 % : 105 / 0,8 = 131,25 ; l'article suit (90 + 262,50).
        $this->reglage('marge_fournitures_defaut', 20);
        $this->assertEqualsWithDelta(131.25, $this->venteFourniture($calculee), 0.0001);
        $this->assertEqualsWithDelta(352.5, (float) $this->lire($article)->json('prix_vente_ht'), 0.001);
        $this->assertSame(2, $this->chez(fn () => Activity::query()->where('event', 'fournitures_recalculees')->count()));

        // Même valeur, ou marge retirée : rien n'est recalculé, les prix restent en l'état.
        $this->reglage('marge_fournitures_defaut', 20);
        $this->reglage('marge_fournitures_defaut', null);
        $this->assertEqualsWithDelta(131.25, $this->venteFourniture($calculee), 0.0001);
        $this->assertSame(2, $this->chez(fn () => Activity::query()->where('event', 'fournitures_recalculees')->count()));
    }

    public function test_le_devis_copie_les_frais_generaux_a_sa_creation(): void
    {
        $fourniture = $this->fourniture(['prix_achat_ht' => 100, 'prix_vente_ht' => 150]);
        $article = $this->article($fourniture);
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));
        $nouveauDevis = fn (): int => $this->agirEn($this->commercial)
            ->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Sol'])->assertCreated()->json('id');
        $ajouter = fn (int $devis) => $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 1])->assertCreated();

        $ancien = $nouveauDevis();
        $this->reglage('frais_generaux_fournitures', 5);
        $nouveau = $nouveauDevis();

        $this->assertEqualsWithDelta(0, (float) $this->agirEn($this->commercial)->getJson("/api/devis/{$ancien}/parametres")->json('parametres.frais_generaux_fournitures'), 0.001);
        $this->assertEqualsWithDelta(5, (float) $this->agirEn($this->commercial)->getJson("/api/devis/{$nouveau}/parametres")->json('parametres.frais_generaux_fournitures'), 0.001);

        // Devis d'avant : revient 60 + 200 = 260. Devis d'après : 60 + 210 = 270. Même prix de vente (390).
        $ligneAncienne = $ajouter($ancien);
        $this->assertEqualsWithDelta(390, (float) $ligneAncienne->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(260, (float) $ligneAncienne->json('prix_revient_unitaire'), 0.001);
        $ligneNouvelle = $ajouter($nouveau);
        $this->assertEqualsWithDelta(390, (float) $ligneNouvelle->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(270, (float) $ligneNouvelle->json('prix_revient_unitaire'), 0.001);

        // « Recalculer le devis » garde les frais généraux : 1,8 h x 40 + 210 = 282 ; vente 108 + 300 = 408.
        $this->agirEn($this->commercial)->putJson("/api/devis/{$nouveau}/parametres", ['coefficient_difficulte' => 1.2])->assertOk();
        $this->agirEn($this->commercial)->postJson("/api/devis/{$nouveau}/recalculer")->assertOk()->assertJsonPath('lignes_recalculees', 1);
        $apres = $this->agirEn($this->commercial)->getJson("/api/devis/{$nouveau}")->assertOk();
        $this->assertEqualsWithDelta(408, (float) $apres->json('lignes.0.prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(282, (float) $apres->json('lignes.0.prix_revient_unitaire'), 0.001);
    }

    public function test_un_article_a_prix_force_passe_sous_le_seuil_est_signale(): void
    {
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_fournitures_min', ['valeur' => 20])->assertOk();
        $fourniture = $this->fourniture(['prix_achat_ht' => 50, 'prix_vente_ht' => 80]);
        $article = $this->article($fourniture);
        $libre = $this->article($fourniture);

        // Prix minimum : 60 + 100 / 0,8 = 185. Un prix forcé de 190 respecte le seuil.
        $reponse = $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}", ['prix_force_ht' => 190])
            ->assertOk()->assertJsonPath('sous_seuil', false);
        $this->assertEqualsWithDelta(185, (float) $reponse->json('prix_minimum_ht'), 0.001);
        $this->agirEn($this->direction)->getJson('/api/bibliotheque-prix?sous_seuil=1')->assertOk()->assertJsonCount(0, 'data');

        // La fourniture augmente (achat 50 -> 60) : minimum 60 + 120 / 0,8 = 210. Rien n'est bloqué, c'est signalé.
        $modif = $this->agirEn($this->direction)->putJson("/api/fournitures/{$fourniture}", ['prix_achat_ht' => 60])
            ->assertOk()
            ->assertJsonPath('articles_mis_a_jour', 2)
            ->assertJsonCount(1, 'articles_sous_seuil')
            ->assertJsonPath('articles_sous_seuil.0.id', $article);
        $this->assertEqualsWithDelta(210, (float) $modif->json('articles_sous_seuil.0.prix_minimum_ht'), 0.001);
        $this->assertEqualsWithDelta(190, (float) $modif->json('articles_sous_seuil.0.prix_force_ht'), 0.001);

        // L'article porte l'indicateur ; un article sans prix forcé ne le porte pas.
        $this->lire($article)->assertJsonPath('sous_seuil', true);
        $this->lire($article, $this->commercial)->assertJsonPath('sous_seuil', true);
        $this->lire($libre)->assertJsonPath('sous_seuil', false)->assertJsonPath('prix_minimum_ht', null);
        $this->agirEn($this->direction)->getJson('/api/bibliotheque-prix?sous_seuil=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $article);

        // Les frais généraux comptent dans le minimum : 60 + 126 / 0,8 = 217,50.
        $this->reglage('frais_generaux_fournitures', 5);
        $this->assertEqualsWithDelta(217.5, (float) $this->lire($article)->json('prix_minimum_ht'), 0.001);

        // Sans bibliotheque.voir_prix_achat : ni indicateur, ni filtre.
        Role::firstOrCreate(['name' => 'Lecteur prix', 'guard_name' => 'web'])->syncPermissions(['bibliotheque.consulter']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $lecteur = $this->creerUtilisateur($this->entreprise, 'Lecteur prix');
        $this->lire($article, $lecteur)->assertJsonMissingPath('sous_seuil')->assertJsonMissingPath('prix_minimum_ht');
        $this->agirEn($lecteur)->getJson('/api/bibliotheque-prix?sous_seuil=1')->assertForbidden();

        // La Direction remonte le prix forcé : l'indicateur tombe.
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}", ['prix_force_ht' => 220])
            ->assertOk()->assertJsonPath('sous_seuil', false);
        $this->agirEn($this->direction)->getJson('/api/bibliotheque-prix?sous_seuil=1')->assertOk()->assertJsonCount(0, 'data');
    }
}
