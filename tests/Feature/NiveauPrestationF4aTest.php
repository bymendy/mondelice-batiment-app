<?php

namespace Tests\Feature;

use App\Models\ArticleComposant;
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
 * Tâche 3.6 bis, lot F4a : niveau de prestation dans la bibliothèque.
 * Variantes économique et haut de gamme d'une ligne « fourniture de la base »,
 * prix de l'article aux trois niveaux.
 *
 * Jeu d'essai : main-d'œuvre 1,5 h (vente 90, revient 60) et 2 m2 de
 * carrelage. Standard : achat 90, vente 150. Économique : achat 45, vente 80.
 * Haut de gamme : achat 180, vente 300.
 */
class NiveauPrestationF4aTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private int $fournisseur;

    private int $standard;

    private int $eco;

    private int $haut;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->chez(fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60, 'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
        ]));

        $this->fournisseur = $this->agirEn($this->direction)
            ->postJson('/api/fournisseurs', ['nom' => 'Point P', 'remise_defaut_pct' => 10])->assertCreated()->json('id');
        $this->standard = $this->fourniture('CAR-STD', 'Carrelage standard', 100, 150);
        $this->eco = $this->fourniture('CAR-ECO', 'Carrelage économique', 50, 80);
        $this->haut = $this->fourniture('CAR-HDG', 'Carrelage haut de gamme', 200, 300);
    }

    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    private function fourniture(string $reference, string $designation, float $tarif, float $vente, string $unite = 'm2'): int
    {
        return $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $this->fournisseur, 'reference' => $reference, 'designation' => $designation,
            'unite' => $unite, 'prix_tarif_ht' => $tarif, 'prix_vente_ht' => $vente,
        ])->assertCreated()->json('id');
    }

    /**
     * Article avec 1,5 h de main-d'œuvre. Renvoie son identifiant.
     */
    private function article(): int
    {
        $id = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-F4-' . uniqid(), 'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5,
        ])->assertCreated();

        return $id;
    }

    /**
     * Ajoute la ligne de carrelage (2 m2). Renvoie la réponse.
     *
     * @param array<string, mixed> $variantes
     * @return \Illuminate\Testing\TestResponse
     */
    private function ligne(int $article, array $variantes = [], ?User $qui = null)
    {
        return $this->agirEn($qui ?? $this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", $variantes + [
            'type' => 'fourniture_base', 'fourniture_id' => $this->standard, 'quantite' => 2,
        ]);
    }

    /**
     * Article complet avec ses deux variantes. Renvoie [article, ligne].
     *
     * @return array{0: int, 1: int}
     */
    private function articleAvecVariantes(): array
    {
        $article = $this->article();
        $ligne = $this->ligne($article, ['fourniture_eco_id' => $this->eco, 'fourniture_haut_id' => $this->haut])->assertCreated()->json('composant.id');

        return [$article, $ligne];
    }

    /**
     * @return array<string, float> Prix de vente par niveau.
     */
    private function prix(int $article, ?User $qui = null): array
    {
        $niveaux = $this->agirEn($qui ?? $this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()->json('niveaux_prestation');

        return collect($niveaux)->mapWithKeys(fn (array $n) => [$n['niveau'] => (float) $n['prix_vente_ht']])->all();
    }

    private function journal(): int
    {
        return $this->chez(fn () => Activity::query()->where('event', 'variantes_gamme_modifiees')->count());
    }

    public function test_une_ligne_recoit_ses_variantes_et_l_article_a_trois_prix(): void
    {
        $article = $this->article();

        $reponse = $this->ligne($article, ['fourniture_eco_id' => $this->eco, 'fourniture_haut_id' => $this->haut])
            ->assertCreated()
            // La ligne reste la fourniture standard.
            ->assertJsonPath('composant.fourniture_id', $this->standard)
            ->assertJsonPath('composant.designation', 'Carrelage standard')
            ->assertJsonPath('composant.fourniture_eco_id', $this->eco)
            ->assertJsonPath('composant.fourniture_haut_id', $this->haut)
            ->assertJsonPath('composant.variantes.economique.fourniture_id', $this->eco)
            ->assertJsonPath('composant.variantes.economique.reference', 'CAR-ECO')
            ->assertJsonPath('composant.variantes.economique.designation', 'Carrelage économique')
            ->assertJsonPath('composant.variantes.economique.archivee', false)
            ->assertJsonPath('composant.variantes.haut_de_gamme.designation', 'Carrelage haut de gamme')
            ->assertJsonPath('article.niveaux_prestation.0.niveau', 'economique')
            ->assertJsonPath('article.niveaux_prestation.0.libelle', 'Économique')
            ->assertJsonPath('article.niveaux_prestation.0.fournitures_remplacees', 1)
            ->assertJsonPath('article.niveaux_prestation.1.niveau', 'standard')
            ->assertJsonPath('article.niveaux_prestation.1.fournitures_remplacees', 0)
            ->assertJsonPath('article.niveaux_prestation.2.niveau', 'haut_de_gamme')
            ->assertJsonPath('article.niveaux_prestation.2.fournitures_remplacees', 1);

        $this->assertEqualsWithDelta(80, (float) $reponse->json('composant.variantes.economique.prix_vente_ht'), 0.0001);
        $this->assertEqualsWithDelta(160, (float) $reponse->json('composant.variantes.economique.total_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(45, (float) $reponse->json('composant.variantes.economique.prix_achat_ht'), 0.0001);
        $this->assertEqualsWithDelta(360, (float) $reponse->json('composant.variantes.haut_de_gamme.total_revient_ht'), 0.001);

        // Le prix de l'article reste celui du niveau standard : 90 + 2 x 150 = 390.
        $this->assertEqualsWithDelta(390, (float) $reponse->json('article.prix_vente_ht'), 0.001);
        // Économique : 90 + 2 x 80 = 250. Haut de gamme : 90 + 2 x 300 = 690.
        $this->assertEqualsWithDelta(250, (float) $reponse->json('article.niveaux_prestation.0.prix_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(390, (float) $reponse->json('article.niveaux_prestation.1.prix_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(690, (float) $reponse->json('article.niveaux_prestation.2.prix_vente_ht'), 0.001);
        // Revient : 60 + 2 x 45 = 150 ; marge (250 - 150) / 250 = 40 %.
        $this->assertEqualsWithDelta(150, (float) $reponse->json('article.niveaux_prestation.0.prix_revient_ht'), 0.001);
        $this->assertEqualsWithDelta(40, (float) $reponse->json('article.niveaux_prestation.0.taux_marge_pct'), 0.01);
        $this->assertEqualsWithDelta(420, (float) $reponse->json('article.niveaux_prestation.2.prix_revient_ht'), 0.001);

        // La liste du sous-détail donne aussi les variantes.
        $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}/composants")->assertOk()
            ->assertJsonPath('data.1.variantes.economique.fourniture_id', $this->eco)
            ->assertJsonMissingPath('data.0.variantes');
        $this->assertSame(1, $this->journal());
    }

    public function test_sans_variante_le_niveau_garde_la_fourniture_standard(): void
    {
        $article = $this->article();
        $this->ligne($article)->assertCreated()
            ->assertJsonPath('composant.fourniture_eco_id', null)
            ->assertJsonPath('composant.variantes.economique', null)
            ->assertJsonPath('composant.variantes.haut_de_gamme', null);

        $this->assertEqualsWithDelta(390, $this->prix($article)['economique'], 0.001);
        $this->assertEqualsWithDelta(390, $this->prix($article)['haut_de_gamme'], 0.001);
        $this->assertSame(0, $this->journal());

        // Une seule variante : l'autre niveau reste au standard.
        $autre = $this->article();
        $this->ligne($autre, ['fourniture_eco_id' => $this->eco])->assertCreated();
        $prix = $this->prix($autre);
        $this->assertEqualsWithDelta(250, $prix['economique'], 0.001);
        $this->assertEqualsWithDelta(390, $prix['standard'], 0.001);
        $this->assertEqualsWithDelta(390, $prix['haut_de_gamme'], 0.001);
    }

    public function test_les_variantes_se_modifient_et_se_retirent(): void
    {
        $article = $this->article();
        $ligne = $this->ligne($article)->assertCreated()->json('composant.id');
        $modifier = fn (array $donnees) => $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants/{$ligne}", $donnees);

        $modifier(['fourniture_haut_id' => $this->haut])->assertOk()
            ->assertJsonPath('composant.fourniture_haut_id', $this->haut)
            ->assertJsonPath('composant.fourniture_eco_id', null);
        $this->assertEqualsWithDelta(690, $this->prix($article)['haut_de_gamme'], 0.001);
        $this->assertSame(1, $this->journal());

        // La quantité change sans toucher aux variantes ; rien de plus au journal des variantes.
        $modifier(['quantite' => 3])->assertOk()->assertJsonPath('composant.fourniture_haut_id', $this->haut);
        $this->assertEqualsWithDelta(990, $this->prix($article)['haut_de_gamme'], 0.001);
        $this->assertSame(1, $this->journal());

        $modifier(['fourniture_haut_id' => null])->assertOk()->assertJsonPath('composant.variantes.haut_de_gamme', null);
        $this->assertEqualsWithDelta(540, $this->prix($article)['haut_de_gamme'], 0.001);
        $this->assertSame(2, $this->journal());

        $trace = $this->chez(fn () => Activity::query()->where('event', 'variantes_gamme_modifiees')->latest('id')->first());
        $this->assertSame($this->haut, $trace->properties['avant']['fourniture_haut_id']);
        $this->assertNull($trace->properties['apres']['fourniture_haut_id']);
        $this->assertSame($ligne, $trace->properties['composant_id']);
    }

    public function test_les_variantes_sont_controlees(): void
    {
        $article = $this->article();
        $refus = fn (array $variantes, string $champ) => $this->ligne($article, $variantes)->assertUnprocessable()->assertJsonValidationErrors($champ);

        // Fourniture inconnue, ou identique à la standard.
        $refus(['fourniture_eco_id' => 999999], 'fourniture_eco_id');
        $refus(['fourniture_haut_id' => $this->standard], 'fourniture_haut_id');
        // Autre unité : la quantité du sous-détail ne vaudrait plus.
        $boite = $this->fourniture('CAR-BTE', 'Carrelage en boîte', 30, 50, 'boite');
        $refus(['fourniture_eco_id' => $boite], 'fourniture_eco_id');
        // Fourniture archivée.
        $archivee = $this->fourniture('CAR-OLD', 'Ancien carrelage', 40, 70);
        $this->agirEn($this->direction)->deleteJson("/api/fournitures/{$archivee}")->assertNoContent();
        $refus(['fourniture_eco_id' => $archivee], 'fourniture_eco_id');
        // Fourniture d'une autre entreprise.
        $autre = $this->creerEntreprise();
        $etranger = $this->creerUtilisateur($autre, 'Direction');
        $ailleurs = $this->agirEn($etranger)->postJson('/api/fournitures', ['designation' => 'Ailleurs', 'unite' => 'm2', 'prix_vente_ht' => 5])->assertCreated()->json('id');
        $refus(['fourniture_eco_id' => $ailleurs], 'fourniture_eco_id');

        // Une ligne libre n'a pas de variante.
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 8, 'fourniture_eco_id' => $this->eco,
        ])->assertUnprocessable()->assertJsonValidationErrors('fourniture_eco_id');
        $libre = $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$article}/composants", [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 8,
        ])->assertCreated()->json('composant.id');
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants/{$libre}", ['fourniture_haut_id' => $this->haut])
            ->assertUnprocessable()->assertJsonValidationErrors('fourniture_haut_id');

        // Mêmes contrôles à la modification.
        $ligne = $this->ligne($article)->assertCreated()->json('composant.id');
        foreach ([$boite, $this->standard, 999999] as $mauvaise) {
            $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants/{$ligne}", ['fourniture_eco_id' => $mauvaise])
                ->assertUnprocessable()->assertJsonValidationErrors('fourniture_eco_id');
        }
        $this->assertSame(0, $this->chez(fn () => ArticleComposant::query()->where(fn ($q) => $q->whereNotNull('fourniture_eco_id')->orWhereNotNull('fourniture_haut_id'))->count()));
    }

    public function test_un_prix_force_suit_l_ecart_des_fournitures(): void
    {
        [$article] = $this->articleAvecVariantes();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}", ['prix_force_ht' => 400])->assertOk();

        // Standard : 400. Économique : 400 - (390 - 250) = 260. Haut de gamme : 400 + (690 - 390) = 700.
        $prix = $this->prix($article);
        $this->assertEqualsWithDelta(260, $prix['economique'], 0.001);
        $this->assertEqualsWithDelta(400, $prix['standard'], 0.001);
        $this->assertEqualsWithDelta(700, $prix['haut_de_gamme'], 0.001);

        // Marge du niveau économique : (260 - 150) / 260 = 42,31 %.
        $niveaux = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()->json('niveaux_prestation');
        $this->assertEqualsWithDelta(42.31, (float) $niveaux[0]['taux_marge_pct'], 0.01);
    }

    public function test_chaque_niveau_a_son_indicateur_sous_le_seuil(): void
    {
        // Haut de gamme vendu avec une marge trop faible : achat 180, vente 190.
        $fin = $this->fourniture('CAR-FIN', 'Carrelage fin', 200, 190);
        $article = $this->article();
        $this->ligne($article, ['fourniture_eco_id' => $this->eco, 'fourniture_haut_id' => $fin])->assertCreated();
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_fournitures_min', ['valeur' => 20])->assertOk();

        // Sans prix forcé, rien n'est contrôlé.
        $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()
            ->assertJsonPath('niveaux_prestation.2.sous_seuil', false)
            ->assertJsonPath('niveaux_prestation.2.prix_minimum_ht', null);

        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}", ['prix_force_ht' => 400])->assertOk();
        $reponse = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()
            // Le niveau standard respecte le seuil : minimum 60 + 180 / 0,8 = 285.
            ->assertJsonPath('sous_seuil', false)
            ->assertJsonPath('niveaux_prestation.0.sous_seuil', false)
            ->assertJsonPath('niveaux_prestation.1.sous_seuil', false)
            // Le haut de gamme passe sous son minimum sans que le standard bouge.
            ->assertJsonPath('niveaux_prestation.2.sous_seuil', true);

        // Économique : prix 400 - 140 = 260, minimum 60 + 90 / 0,8 = 172,50.
        $this->assertEqualsWithDelta(260, (float) $reponse->json('niveaux_prestation.0.prix_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(172.5, (float) $reponse->json('niveaux_prestation.0.prix_minimum_ht'), 0.001);
        $this->assertEqualsWithDelta(285, (float) $reponse->json('niveaux_prestation.1.prix_minimum_ht'), 0.001);
        // Haut de gamme : prix 400 + 80 = 480, minimum 60 + 360 / 0,8 = 510.
        $this->assertEqualsWithDelta(480, (float) $reponse->json('niveaux_prestation.2.prix_vente_ht'), 0.001);
        $this->assertEqualsWithDelta(510, (float) $reponse->json('niveaux_prestation.2.prix_minimum_ht'), 0.001);

        // Le Commercial voit les marges, donc l'indicateur ; un rôle sans les prix d'achat ne le voit pas.
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $this->agirEn($commercial)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()->assertJsonPath('niveaux_prestation.2.sous_seuil', true);
        Role::firstOrCreate(['name' => 'Lecteur prix', 'guard_name' => 'web'])->syncPermissions(['bibliotheque.consulter']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $lecteur = $this->creerUtilisateur($this->entreprise, 'Lecteur prix');
        $this->agirEn($lecteur)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()
            ->assertJsonMissingPath('niveaux_prestation.2.sous_seuil')
            ->assertJsonMissingPath('niveaux_prestation.2.prix_minimum_ht');
    }

    public function test_les_variantes_suivent_la_base_de_fournitures(): void
    {
        [$article] = $this->articleAvecVariantes();

        // Le prix de la variante économique change : le niveau économique suit, le standard non.
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$this->eco}", ['prix_vente_ht' => 90])->assertOk();
        $prix = $this->prix($article);
        $this->assertEqualsWithDelta(270, $prix['economique'], 0.001);
        $this->assertEqualsWithDelta(390, $prix['standard'], 0.001);

        // La fiche de la fourniture compte l'article qui l'utilise comme variante.
        $this->agirEn($this->direction)->getJson("/api/fournitures/{$this->eco}")->assertOk()->assertJsonPath('articles_utilisant', 1);
        $this->agirEn($this->direction)->getJson("/api/fournitures/{$this->standard}")->assertOk()->assertJsonPath('articles_utilisant', 1);

        // Une variante archivée reste dans l'article, avec son dernier prix.
        $this->agirEn($this->direction)->deleteJson("/api/fournitures/{$this->eco}")->assertNoContent();
        $this->assertEqualsWithDelta(270, $this->prix($article)['economique'], 0.001);
        $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}/composants")->assertOk()
            ->assertJsonPath('data.1.variantes.economique.archivee', true)
            ->assertJsonPath('data.1.variantes.haut_de_gamme.archivee', false);
    }

    public function test_les_frais_generaux_entrent_dans_le_revient_des_variantes(): void
    {
        [$article] = $this->articleAvecVariantes();
        $this->chez(fn () => Parametre::ecrire('frais_generaux_fournitures', 10));

        $reponse = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk();
        // Économique : 60 + 2 x 45 x 1,10 = 159. Haut de gamme : 60 + 2 x 180 x 1,10 = 456.
        $this->assertEqualsWithDelta(159, (float) $reponse->json('niveaux_prestation.0.prix_revient_ht'), 0.001);
        $this->assertEqualsWithDelta(456, (float) $reponse->json('niveaux_prestation.2.prix_revient_ht'), 0.001);
        // Les prix de vente saisis ne bougent pas.
        $this->assertEqualsWithDelta(250, (float) $reponse->json('niveaux_prestation.0.prix_vente_ht'), 0.001);
    }

    public function test_l_enregistrement_complet_garde_ou_retire_les_variantes(): void
    {
        [$article, $ligne] = $this->articleAvecVariantes();
        $mainOeuvre = (int) $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}/composants")->json('data.0.id');
        $enregistrer = fn (array $carrelage) => $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants", ['composants' => [
            ['id' => $mainOeuvre, 'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5],
            $carrelage + ['id' => $ligne, 'type' => 'fourniture_base', 'fourniture_id' => $this->standard, 'quantite' => 2],
        ]]);
        $this->assertSame(1, $this->journal());

        // Les variantes redonnées sont gardées : rien de plus au journal.
        $enregistrer(['fourniture_eco_id' => $this->eco, 'fourniture_haut_id' => $this->haut])->assertOk()
            ->assertJsonPath('data.1.fourniture_eco_id', $this->eco)->assertJsonPath('data.1.fourniture_haut_id', $this->haut);
        $this->assertSame(1, $this->journal());

        // Chaque ligne est décrite en entier : une variante absente est retirée.
        $enregistrer(['fourniture_eco_id' => $this->eco])->assertOk()
            ->assertJsonPath('data.1.fourniture_eco_id', $this->eco)->assertJsonPath('data.1.fourniture_haut_id', null);
        $this->assertSame(2, $this->journal());
        $this->assertEqualsWithDelta(390, $this->prix($article)['haut_de_gamme'], 0.001);

        // Une variante invalide annule tout l'enregistrement.
        $enregistrer(['fourniture_eco_id' => $this->standard])->assertUnprocessable()->assertJsonValidationErrors('composants.1.fourniture_eco_id');
        $this->assertEqualsWithDelta(250, $this->prix($article)['economique'], 0.001);

        // Une nouvelle ligne peut arriver avec ses variantes.
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}/composants", ['composants' => [
            ['type' => 'fourniture_base', 'fourniture_id' => $this->standard, 'quantite' => 1, 'fourniture_haut_id' => $this->haut],
        ]])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.fourniture_haut_id', $this->haut);
        $this->assertSame(3, $this->journal());
    }

    public function test_les_prix_d_achat_des_variantes_sont_masques_sans_la_permission(): void
    {
        [$article] = $this->articleAvecVariantes();
        Role::firstOrCreate(['name' => 'Lecteur prix', 'guard_name' => 'web'])->syncPermissions(['bibliotheque.consulter']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $lecteur = $this->creerUtilisateur($this->entreprise, 'Lecteur prix');

        $this->agirEn($lecteur)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()
            ->assertJsonPath('niveaux_prestation.0.niveau', 'economique')
            ->assertJsonMissingPath('niveaux_prestation.0.prix_revient_ht')
            ->assertJsonMissingPath('niveaux_prestation.0.taux_marge_pct');
        $this->assertEqualsWithDelta(250, $this->prix($article, $lecteur)['economique'], 0.001);

        $this->agirEn($lecteur)->getJson("/api/bibliotheque-prix/{$article}/composants")->assertOk()
            ->assertJsonPath('data.1.variantes.economique.designation', 'Carrelage économique')
            ->assertJsonMissingPath('data.1.variantes.economique.prix_achat_ht')
            ->assertJsonMissingPath('data.1.variantes.economique.total_revient_ht');
    }

    public function test_les_droits_sont_ceux_du_sous_detail(): void
    {
        [$article, $ligne] = $this->articleAvecVariantes();

        // Le Conducteur de travaux modifie le sous-détail d'un article de l'entreprise.
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->agirEn($conducteur)->putJson("/api/bibliotheque-prix/{$article}/composants/{$ligne}", ['fourniture_eco_id' => null])->assertOk();

        // Le Commercial et l'Administratif consultent les trois prix mais ne règlent pas les variantes.
        foreach (['Commercial', 'Administratif'] as $role) {
            $utilisateur = $this->creerUtilisateur($this->entreprise, $role);
            $this->agirEn($utilisateur)->putJson("/api/bibliotheque-prix/{$article}/composants/{$ligne}", ['fourniture_eco_id' => $this->eco])->assertForbidden();
            $this->assertEqualsWithDelta(690, $this->prix($article, $utilisateur)['haut_de_gamme'], 0.001);
        }
    }
}
