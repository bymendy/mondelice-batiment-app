<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Fourniture;
use App\Models\LigneDevis;
use App\Models\Parametre;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tâche 3.6 bis, lot F1 : fournisseurs, Base de fournitures, ligne
 * « fourniture de la base » dans le sous-détail, lien vivant avec les articles.
 */
class FournituresF1Test extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private User $conducteur;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
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

    private function margeParDefaut(float $pct): void
    {
        $this->chez(fn () => Parametre::ecrire('marge_fournitures_defaut', $pct));
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function fournisseur(array $donnees = []): int
    {
        return $this->agirEn($this->direction)
            ->postJson('/api/fournisseurs', $donnees + ['nom' => 'Point P', 'remise_defaut_pct' => 10])
            ->assertCreated()->json('id');
    }

    /**
     * Fourniture par défaut : tarif 100, remise du fournisseur 10 %, donc
     * achat net 90 ; vente saisie 150.
     *
     * @param array<string, mixed> $donnees
     */
    private function fourniture(int $fournisseur, array $donnees = []): int
    {
        return $this->agirEn($this->direction)->postJson('/api/fournitures', $donnees + [
            'fournisseur_id' => $fournisseur, 'reference' => 'CAR-001', 'designation' => 'Carrelage 60x60',
            'unite' => 'm2', 'prix_tarif_ht' => 100, 'prix_vente_ht' => 150,
        ])->assertCreated()->json('id');
    }

    /**
     * Article : 1,5 h d'ouvrier (90 EUR) et, si demandé, une fourniture de la base.
     */
    private function article(?int $fourniture = null, float $quantite = 2): int
    {
        $id = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-F1-' . uniqid(), 'designation' => 'Pose de carrelage',
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

    private function prixArticle(int $article): float
    {
        return (float) $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()->json('prix_vente_ht');
    }

    public function test_la_table_des_fournisseurs_remplace_suppliers(): void
    {
        $this->assertFalse(Schema::hasTable('suppliers'));
        $this->assertTrue(Schema::hasTable('fournisseurs'));
        $this->assertTrue(Schema::hasColumns('fournisseurs', ['nom', 'telephone', 'remise_defaut_pct', 'actif', 'deleted_at']));
        $this->assertTrue(Schema::hasTable('fournitures'));
        $this->assertTrue(Schema::hasColumn('lignes_devis_composants', 'fourniture_id'));
    }

    public function test_les_droits_sur_les_fournisseurs(): void
    {
        $id = $this->fournisseur();

        // Direction et Conducteur de travaux créent et modifient.
        $this->agirEn($this->conducteur)->postJson('/api/fournisseurs', ['nom' => 'Cedeo'])->assertCreated();
        $this->agirEn($this->conducteur)->putJson("/api/fournisseurs/{$id}", ['telephone' => '0102030405'])
            ->assertOk()->assertJsonPath('telephone', '0102030405');

        // Le Commercial et l'Administratif consultent seulement.
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');
        foreach ([$this->commercial, $administratif] as $lecteur) {
            $this->agirEn($lecteur)->getJson('/api/fournisseurs')->assertOk()->assertJsonCount(2, 'data');
            $this->agirEn($lecteur)->getJson("/api/fournisseurs/{$id}")->assertOk()->assertJsonPath('nom', 'Point P');
            $this->agirEn($lecteur)->postJson('/api/fournisseurs', ['nom' => 'Refus'])->assertForbidden();
            $this->agirEn($lecteur)->putJson("/api/fournisseurs/{$id}", ['nom' => 'Refus'])->assertForbidden();
        }

        // Le terrain n'y a pas accès. Seule la Direction archive.
        $terrain = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $this->agirEn($terrain)->getJson('/api/fournisseurs')->assertForbidden();
        $this->agirEn($this->conducteur)->deleteJson("/api/fournisseurs/{$id}")->assertForbidden();
        $this->agirEn($this->direction)->deleteJson("/api/fournisseurs/{$id}")->assertNoContent();
        $this->agirEn($this->direction)->getJson("/api/fournisseurs/{$id}")->assertNotFound();

        $this->agirEn($this->direction)->postJson('/api/fournisseurs', [])->assertUnprocessable()->assertJsonValidationErrors('nom');
        $this->agirEn($this->direction)->postJson('/api/fournisseurs', ['nom' => 'X', 'remise_defaut_pct' => 120])
            ->assertUnprocessable()->assertJsonValidationErrors('remise_defaut_pct');
    }

    public function test_un_fournisseur_qui_a_des_fournitures_ne_s_archive_pas(): void
    {
        $fournisseur = $this->fournisseur();
        $fourniture = $this->fourniture($fournisseur);

        $this->agirEn($this->direction)->deleteJson("/api/fournisseurs/{$fournisseur}")
            ->assertUnprocessable()->assertJsonValidationErrors('fournisseur');

        $this->agirEn($this->direction)->deleteJson("/api/fournitures/{$fourniture}")->assertNoContent();
        $this->agirEn($this->direction)->deleteJson("/api/fournisseurs/{$fournisseur}")->assertNoContent();
    }

    public function test_le_prix_d_achat_net_se_calcule_du_tarif_et_de_la_remise(): void
    {
        $fournisseur = $this->fournisseur();

        // Remise du fournisseur (10 %) : 100 -> 90.
        $reponse = $this->agirEn($this->direction)->getJson('/api/fournitures/' . $this->fourniture($fournisseur))
            ->assertOk()
            ->assertJsonPath('fournisseur.nom', 'Point P')
            ->assertJsonPath('prix_vente_saisi', true)
            ->assertJsonPath('remise_pct', null)
            ->assertJsonPath('articles_utilisant', 0);
        $this->assertEqualsWithDelta(90, (float) $reponse->json('prix_achat_ht'), 0.0001);
        $this->assertEqualsWithDelta(10, (float) $reponse->json('remise_appliquee_pct'), 0.0001);
        $this->assertEqualsWithDelta(150, (float) $reponse->json('prix_vente_ht'), 0.0001);

        // Remise propre à la fourniture (25 %) : 100 -> 75.
        $propre = $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $fournisseur, 'reference' => 'CAR-002', 'designation' => 'Plinthe', 'unite' => 'ml',
            'prix_tarif_ht' => 100, 'remise_pct' => 25, 'prix_vente_ht' => 120,
        ])->assertCreated();
        $this->assertEqualsWithDelta(75, (float) $propre->json('prix_achat_ht'), 0.0001);

        // Sans prix tarif : le prix d'achat se saisit.
        $direct = $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'designation' => 'Colle', 'unite' => 'sac', 'prix_achat_ht' => 50, 'prix_vente_ht' => 80,
        ])->assertCreated()->assertJsonPath('fournisseur', null)->assertJsonPath('prix_tarif_ht', null);
        $this->assertEqualsWithDelta(50, (float) $direct->json('prix_achat_ht'), 0.0001);

        // Prix tarif et prix d'achat ensemble : refusé (l'achat est calculé).
        $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'designation' => 'Joint', 'unite' => 'u', 'prix_tarif_ht' => 100, 'prix_achat_ht' => 80, 'prix_vente_ht' => 120,
        ])->assertUnprocessable()->assertJsonValidationErrors('prix_achat_ht');
    }

    public function test_le_prix_de_vente_se_calcule_avec_la_marge_par_defaut(): void
    {
        $fournisseur = $this->fournisseur();
        $sansVente = [
            'fournisseur_id' => $fournisseur, 'designation' => 'Carrelage', 'unite' => 'm2', 'prix_tarif_ht' => 100,
        ];

        // Ni prix de vente ni marge par défaut : refusé.
        $this->agirEn($this->direction)->postJson('/api/fournitures', $sansVente)
            ->assertUnprocessable()->assertJsonValidationErrors('prix_vente_ht');
        // Ni prix de vente ni prix d'achat : refusé.
        $this->agirEn($this->direction)->postJson('/api/fournitures', ['designation' => 'Vis', 'unite' => 'u'])
            ->assertUnprocessable()->assertJsonValidationErrors('prix_vente_ht');

        // Marge de 20 % sur le prix de vente : 90 / 0,8 = 112,50.
        $this->margeParDefaut(20);
        $reponse = $this->agirEn($this->direction)->postJson('/api/fournitures', $sansVente)
            ->assertCreated()->assertJsonPath('prix_vente_saisi', false);
        $this->assertEqualsWithDelta(112.5, (float) $reponse->json('prix_vente_ht'), 0.0001);

        // Un prix de vente saisi le remplace ; le remettre à vide le recalcule.
        $id = $reponse->json('id');
        $saisi = $this->agirEn($this->direction)->putJson("/api/fournitures/{$id}", ['prix_vente_ht' => 130])
            ->assertOk()->assertJsonPath('prix_vente_saisi', true);
        $this->assertEqualsWithDelta(130, (float) $saisi->json('prix_vente_ht'), 0.0001);
        // Changer la remise ne touche pas un prix de vente saisi.
        $remise = $this->agirEn($this->direction)->putJson("/api/fournitures/{$id}", ['remise_pct' => 20])->assertOk();
        $this->assertEqualsWithDelta(80, (float) $remise->json('prix_achat_ht'), 0.0001);
        $this->assertEqualsWithDelta(130, (float) $remise->json('prix_vente_ht'), 0.0001);
        // 80 / 0,8 = 100.
        $calcule = $this->agirEn($this->direction)->putJson("/api/fournitures/{$id}", ['prix_vente_ht' => null])
            ->assertOk()->assertJsonPath('prix_vente_saisi', false);
        $this->assertEqualsWithDelta(100, (float) $calcule->json('prix_vente_ht'), 0.0001);
    }

    public function test_les_droits_sur_les_fournitures_et_les_prix_d_achat(): void
    {
        $fournisseur = $this->fournisseur();
        $id = $this->fourniture($fournisseur);

        // Le Conducteur de travaux crée et modifie ; le Commercial consulte et voit les prix d'achat.
        $this->agirEn($this->conducteur)->putJson("/api/fournitures/{$id}", ['designation' => 'Carrelage gris'])->assertOk();
        $this->agirEn($this->commercial)->postJson('/api/fournitures', ['designation' => 'X', 'unite' => 'u', 'prix_vente_ht' => 1])->assertForbidden();
        $this->agirEn($this->commercial)->putJson("/api/fournitures/{$id}", ['designation' => 'X'])->assertForbidden();
        $vue = $this->agirEn($this->commercial)->getJson("/api/fournitures/{$id}")->assertOk();
        $this->assertEqualsWithDelta(90, (float) $vue->json('prix_achat_ht'), 0.0001);
        $this->assertEqualsWithDelta(10, (float) $this->agirEn($this->commercial)->getJson("/api/fournisseurs/{$fournisseur}")->json('remise_defaut_pct'), 0.0001);

        // Sans bibliotheque.voir_prix_achat : ni tarif, ni remise, ni prix d'achat.
        Role::firstOrCreate(['name' => 'Lecteur prix', 'guard_name' => 'web'])->syncPermissions(['bibliotheque.consulter']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $lecteur = $this->creerUtilisateur($this->entreprise, 'Lecteur prix');
        $this->agirEn($lecteur)->getJson("/api/fournitures/{$id}")
            ->assertOk()
            ->assertJsonPath('designation', 'Carrelage gris')
            ->assertJsonMissingPath('prix_achat_ht')
            ->assertJsonMissingPath('prix_tarif_ht')
            ->assertJsonMissingPath('remise_pct')
            ->assertJsonMissingPath('remise_appliquee_pct');
        $this->agirEn($lecteur)->getJson('/api/fournitures')->assertOk()->assertJsonMissingPath('data.0.prix_achat_ht');
        $this->agirEn($lecteur)->getJson("/api/fournisseurs/{$fournisseur}")->assertOk()->assertJsonMissingPath('remise_defaut_pct');

        // Seule la Direction archive.
        $this->agirEn($this->conducteur)->deleteJson("/api/fournitures/{$id}")->assertForbidden();
        $this->agirEn($this->direction)->deleteJson("/api/fournitures/{$id}")->assertNoContent();
        $this->agirEn($this->direction)->getJson("/api/fournitures/{$id}")->assertNotFound();
    }

    public function test_une_reference_est_unique_par_fournisseur(): void
    {
        $pointP = $this->fournisseur();
        $cedeo = $this->fournisseur(['nom' => 'Cedeo', 'remise_defaut_pct' => 0]);
        $id = $this->fourniture($pointP);

        $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $pointP, 'reference' => 'CAR-001', 'designation' => 'Doublon', 'unite' => 'u', 'prix_vente_ht' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('reference');

        // La même référence chez un autre fournisseur est permise.
        $autre = $this->fourniture($cedeo);
        // La déplacer chez le premier fournisseur créerait un doublon.
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$autre}", ['fournisseur_id' => $pointP])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        // Se modifier soi-même ne compte pas comme un doublon.
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$id}", ['reference' => 'CAR-001', 'designation' => 'Carrelage'])->assertOk();

        // Une référence archivée redevient libre.
        $this->agirEn($this->direction)->deleteJson("/api/fournitures/{$id}")->assertNoContent();
        $this->fourniture($pointP);
    }

    public function test_la_liste_se_filtre(): void
    {
        $pointP = $this->fournisseur();
        $cedeo = $this->fournisseur(['nom' => 'Cedeo', 'remise_defaut_pct' => 0]);
        $this->fourniture($pointP);
        $this->fourniture($cedeo, ['reference' => 'TUB-9', 'designation' => 'Tube cuivre', 'unite' => 'ml']);
        $inactive = $this->fourniture($cedeo, ['reference' => 'TUB-10', 'designation' => 'Tube PER', 'unite' => 'ml', 'actif' => false]);

        $this->agirEn($this->commercial)->getJson('/api/fournitures')->assertOk()->assertJsonCount(3, 'data');
        $this->agirEn($this->commercial)->getJson('/api/fournitures?recherche=tube')->assertOk()->assertJsonCount(2, 'data');
        $this->agirEn($this->commercial)->getJson('/api/fournitures?recherche=car-001')->assertOk()->assertJsonCount(1, 'data');
        $this->agirEn($this->commercial)->getJson("/api/fournitures?fournisseur_id={$pointP}")->assertOk()->assertJsonCount(1, 'data');
        $this->agirEn($this->commercial)->getJson('/api/fournitures?actif=1')->assertOk()->assertJsonCount(2, 'data');
        $this->agirEn($this->commercial)->getJson('/api/fournisseurs?recherche=cede')->assertOk()->assertJsonCount(1, 'data');

        // Une fourniture inactive ne s'ajoute pas à un article.
        $article = $this->article();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_base', 'fourniture_id' => $inactive, 'quantite' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('fourniture_id');
    }

    public function test_une_ligne_d_article_reprend_la_fourniture_de_la_base(): void
    {
        $fourniture = $this->fourniture($this->fournisseur());
        $article = $this->article();

        $reponse = $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_base', 'fourniture_id' => $fourniture, 'quantite' => 2,
            // Ces champs sont ignorés : ils viennent de la fourniture.
            'designation' => 'Autre nom', 'unite' => 'u', 'prix_vente_ht' => 1, 'prix_achat_ht' => 1,
        ])->assertCreated()
            ->assertJsonPath('composant.type', 'fourniture_base')
            ->assertJsonPath('composant.fourniture_id', $fourniture)
            ->assertJsonPath('composant.designation', 'Carrelage 60x60')
            ->assertJsonPath('composant.unite', 'm2');
        $this->assertEqualsWithDelta(150, (float) $reponse->json('composant.prix_vente_ht'), 0.0001);
        $this->assertEqualsWithDelta(90, (float) $reponse->json('composant.prix_achat_ht'), 0.0001);
        $this->assertEqualsWithDelta(300, (float) $reponse->json('composant.total_vente_ht'), 0.0001);
        // 1,5 h x 60 + 2 x 150 = 390.
        $this->assertEqualsWithDelta(390, $this->prixArticle($article), 0.001);
        $this->agirEn($this->direction)->getJson("/api/fournitures/{$fourniture}")->assertOk()->assertJsonPath('articles_utilisant', 1);

        // La ligne ne se modifie que par sa quantité.
        $ligne = $reponse->json('composant.id');
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants/{$ligne}", ['prix_vente_ht' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('prix_vente_ht');
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants/{$ligne}", ['designation' => 'Autre'])
            ->assertUnprocessable()->assertJsonValidationErrors('designation');
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants/{$ligne}", ['quantite' => 3])->assertOk();
        $this->assertEqualsWithDelta(540, $this->prixArticle($article), 0.001);

        // Une fourniture inconnue, ou sans identifiant, est refusée ; une ligne libre ne cite pas de fourniture.
        foreach ([['fourniture_id' => 999999], []] as $cas) {
            $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", $cas + ['type' => 'fourniture_base', 'quantite' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('fourniture_id');
        }
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_libre', 'fourniture_id' => $fourniture, 'designation' => 'Colle', 'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 8,
        ])->assertUnprocessable()->assertJsonValidationErrors('fourniture_id');
    }

    public function test_un_changement_de_prix_met_a_jour_les_articles_mais_pas_les_devis(): void
    {
        $fourniture = $this->fourniture($this->fournisseur());
        $premier = $this->article($fourniture);
        $second = $this->article($fourniture, 1);
        $sans = $this->article();

        // Une ligne de devis écrite avant le changement : 390.
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));
        $devis = $this->agirEn($this->commercial)->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Sol'])->assertCreated()->json('id');
        $ligne = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis}/lignes/depuis-article", ['article_prix_id' => $premier, 'quantite' => 1])
            ->assertCreated()
            ->assertJsonPath('composants.1.type', 'fourniture_base')
            ->assertJsonPath('composants.1.fourniture_id', $fourniture);
        $this->assertEqualsWithDelta(390, (float) $ligne->json('prix_unitaire_ht'), 0.001);

        // Le prix de vente passe de 150 à 160, la désignation change.
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$fourniture}", ['prix_vente_ht' => 160, 'designation' => 'Carrelage 60x60 gris'])
            ->assertOk()->assertJsonPath('articles_mis_a_jour', 2);

        $this->assertEqualsWithDelta(410, $this->prixArticle($premier), 0.001);
        $this->assertEqualsWithDelta(250, $this->prixArticle($second), 0.001);
        $this->assertEqualsWithDelta(90, $this->prixArticle($sans), 0.001);
        $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$premier}/composants")
            ->assertOk()->assertJsonPath('data.1.designation', 'Carrelage 60x60 gris');

        // Le devis déjà écrit garde son prix ; une nouvelle ligne prend le nouveau.
        $figee = $this->chez(fn () => LigneDevis::query()->with('composants')->findOrFail($ligne->json('id')));
        $this->assertEqualsWithDelta(390, (float) $figee->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(150, (float) $figee->composants[1]->prix_vente_ht, 0.0001);
        $this->assertSame('Carrelage 60x60', $figee->composants[1]->designation);
        $nouvelle = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis}/lignes/depuis-article", ['article_prix_id' => $premier, 'quantite' => 1])->assertCreated();
        $this->assertEqualsWithDelta(410, (float) $nouvelle->json('prix_unitaire_ht'), 0.001);

        // Sans changement, rien n'est mis à jour ni journalisé.
        $avant = $this->chez(fn () => Activity::query()->where('event', 'fourniture_modifiee')->count());
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$fourniture}", ['prix_vente_ht' => 160])
            ->assertOk()->assertJsonPath('articles_mis_a_jour', 0);
        $this->assertSame($avant, $this->chez(fn () => Activity::query()->where('event', 'fourniture_modifiee')->count()));
    }

    public function test_la_remise_du_fournisseur_recalcule_ses_fournitures_et_leurs_articles(): void
    {
        $this->margeParDefaut(20);
        $fournisseur = $this->fournisseur();
        // Vente calculée : 90 / 0,8 = 112,50.
        $calculee = $this->fourniture($fournisseur, ['prix_vente_ht' => null]);
        // Remise propre et vente saisie : ne suivent pas le fournisseur.
        $propre = $this->fourniture($fournisseur, ['reference' => 'CAR-002', 'remise_pct' => 5]);
        $article = $this->article($calculee);
        $this->assertEqualsWithDelta(315, $this->prixArticle($article), 0.001);

        // Remise de 10 % à 20 % : achat 80, vente 100.
        $this->agirEn($this->direction)->putJson("/api/fournisseurs/{$fournisseur}", ['remise_defaut_pct' => 20])
            ->assertOk()
            ->assertJsonPath('fournitures_recalculees', 1)
            ->assertJsonPath('articles_mis_a_jour', 1);

        $vue = $this->agirEn($this->direction)->getJson("/api/fournitures/{$calculee}")->assertOk();
        $this->assertEqualsWithDelta(80, (float) $vue->json('prix_achat_ht'), 0.0001);
        $this->assertEqualsWithDelta(100, (float) $vue->json('prix_vente_ht'), 0.0001);
        $this->assertEqualsWithDelta(290, $this->prixArticle($article), 0.001);
        $this->assertEqualsWithDelta(95, (float) $this->agirEn($this->direction)->getJson("/api/fournitures/{$propre}")->json('prix_achat_ht'), 0.0001);

        // Changer autre chose que la remise ne recalcule rien.
        $this->agirEn($this->direction)->putJson("/api/fournisseurs/{$fournisseur}", ['notes' => 'Livraison le mardi'])
            ->assertOk()->assertJsonPath('fournitures_recalculees', 0);
    }

    public function test_le_sous_detail_complet_accepte_une_fourniture_de_la_base(): void
    {
        $fournisseur = $this->fournisseur();
        $carrelage = $this->fourniture($fournisseur);
        $plinthe = $this->fourniture($fournisseur, ['reference' => 'PLI-1', 'designation' => 'Plinthe', 'unite' => 'ml', 'prix_tarif_ht' => 10, 'prix_vente_ht' => 15]);
        $article = $this->article();

        $reponse = $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants", ['composants' => [
            ['type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1],
            ['type' => 'fourniture_base', 'fourniture_id' => $carrelage, 'quantite' => 3],
            ['type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 2, 'unite' => 'u', 'prix_vente_ht' => 8],
        ]])->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.designation', 'Carrelage 60x60')
            ->assertJsonPath('data.1.fourniture_id', $carrelage)
            ->assertJsonPath('data.2.fourniture_id', null);
        // 60 + 3 x 150 + 16 = 526.
        $this->assertEqualsWithDelta(526, $this->prixArticle($article), 0.001);

        // Renvoyer les lignes telles que l'API les donne, en changeant la fourniture de la 2e.
        $lignes = $reponse->json('data');
        $lignes[1]['fourniture_id'] = $plinthe;
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants", ['composants' => $lignes])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.id', $lignes[1]['id'])
            ->assertJsonPath('data.1.designation', 'Plinthe')
            ->assertJsonPath('data.1.unite', 'ml');
        // 60 + 3 x 15 + 16 = 121.
        $this->assertEqualsWithDelta(121, $this->prixArticle($article), 0.001);

        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants", ['composants' => [
            ['type' => 'fourniture_base', 'fourniture_id' => 999999, 'quantite' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors('composants.0.fourniture_id');
    }

    public function test_une_fourniture_archivee_reste_dans_les_articles_mais_ne_s_ajoute_plus(): void
    {
        $fourniture = $this->fourniture($this->fournisseur());
        $article = $this->article($fourniture);

        $this->agirEn($this->direction)->deleteJson("/api/fournitures/{$fourniture}")->assertNoContent();

        $this->assertEqualsWithDelta(390, $this->prixArticle($article), 0.001);
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_base', 'fourniture_id' => $fourniture, 'quantite' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('fourniture_id');
        $this->assertNotNull($this->chez(fn () => Fourniture::withTrashed()->find($fourniture)));
    }

    public function test_les_fournitures_d_une_autre_entreprise_sont_introuvables(): void
    {
        $fournisseur = $this->fournisseur();
        $fourniture = $this->fourniture($fournisseur);

        $autre = $this->creerEntreprise();
        $etranger = $this->creerUtilisateur($autre, 'Direction');
        $this->dansEntreprise($autre, fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 50, 'cout_revient_ht' => 30, 'par_defaut' => true, 'ordre' => 1,
        ]));

        $this->agirEn($etranger)->getJson('/api/fournitures')->assertOk()->assertJsonCount(0, 'data');
        $this->agirEn($etranger)->getJson('/api/fournisseurs')->assertOk()->assertJsonCount(0, 'data');
        $this->agirEn($etranger)->getJson("/api/fournitures/{$fourniture}")->assertNotFound();
        $this->agirEn($etranger)->putJson("/api/fournitures/{$fourniture}", ['prix_vente_ht' => 1])->assertNotFound();
        $this->agirEn($etranger)->deleteJson("/api/fournisseurs/{$fournisseur}")->assertNotFound();
        $this->agirEn($etranger)->postJson('/api/fournitures', [
            'fournisseur_id' => $fournisseur, 'designation' => 'X', 'unite' => 'u', 'prix_vente_ht' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('fournisseur_id');

        // La fourniture d'une autre entreprise ne s'ajoute pas à un article.
        $article = $this->agirEn($etranger)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-X', 'designation' => 'Pose', 'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');
        $this->agirEn($etranger)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_base', 'fourniture_id' => $fourniture, 'quantite' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('fourniture_id');
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $this->getJson('/api/fournitures')->assertUnauthorized();
        $this->postJson('/api/fournitures', [])->assertUnauthorized();
        $this->getJson('/api/fournisseurs')->assertUnauthorized();
        $this->putJson('/api/fournisseurs/1', [])->assertUnauthorized();
    }

    public function test_les_ecritures_sont_journalisees(): void
    {
        $fournisseur = $this->fournisseur();
        $fourniture = $this->fourniture($fournisseur);
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$fourniture}", ['prix_vente_ht' => 160])->assertOk();
        $this->agirEn($this->direction)->putJson("/api/fournisseurs/{$fournisseur}", ['remise_defaut_pct' => 15])->assertOk();
        $this->agirEn($this->direction)->deleteJson("/api/fournitures/{$fourniture}")->assertNoContent();

        foreach (['fournisseur_cree', 'fourniture_creee', 'fourniture_modifiee', 'fournisseur_modifie', 'fourniture_archivee'] as $evenement) {
            $this->assertSame(1, $this->chez(fn () => Activity::query()->where('event', $evenement)->count()), $evenement);
        }

        $trace = $this->chez(fn () => Activity::query()->where('event', 'fourniture_modifiee')->firstOrFail());
        $this->assertSame($fourniture, (int) $trace->subject_id);
        $this->assertSame($this->direction->id, (int) $trace->causer_id);
        $this->assertEqualsWithDelta(150, (float) $trace->properties['avant']['prix_vente_ht'], 0.0001);
        $this->assertEqualsWithDelta(160, (float) $trace->properties['apres']['prix_vente_ht'], 0.0001);
    }
}
