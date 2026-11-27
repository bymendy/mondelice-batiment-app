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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tâche 3.6 bis, lot F4b : niveau de prestation dans le devis (réglage de
 * l'entreprise, paramètre du devis, niveau par ligne, recalcul), et
 * indicateur « sous le seuil » sur les trois niveaux.
 *
 * Jeu d'essai : main-d'œuvre 1,5 h (vente 90, revient 60) et 2 m2 de
 * carrelage. Standard : achat 90, vente 150. Économique : achat 45, vente 80.
 * Haut de gamme : achat 180, vente 300. Prix de l'article : 250 / 390 / 690.
 */
class NiveauPrestationF4bTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private User $commercial;

    private int $fournisseur;

    private int $standard;

    private int $eco;

    private int $haut;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
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

    private function fourniture(string $reference, string $designation, float $tarif, ?float $vente): int
    {
        return $this->agirEn($this->direction)->postJson('/api/fournitures', array_filter([
            'fournisseur_id' => $this->fournisseur, 'reference' => $reference, 'designation' => $designation,
            'unite' => 'm2', 'prix_tarif_ht' => $tarif, 'prix_vente_ht' => $vente,
        ], static fn ($v): bool => $v !== null))->assertCreated()->json('id');
    }

    /**
     * Article : 1,5 h de main-d'œuvre et 2 m2 de carrelage, avec ses variantes.
     *
     * @param array<string, int> $variantes
     */
    private function article(?array $variantes = null): int
    {
        $variantes ??= ['fourniture_eco_id' => $this->eco, 'fourniture_haut_id' => $this->haut];
        $id = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-F4B-' . uniqid(), 'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5,
        ])->assertCreated();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", $variantes + [
            'type' => 'fourniture_base', 'fourniture_id' => $this->standard, 'quantite' => 2,
        ])->assertCreated();

        return $id;
    }

    private function nouveauDevis(): int
    {
        $client = $this->chez(fn () => Client::create(['type' => 'particulier', 'nom' => 'Dupont']));

        return $this->agirEn($this->commercial)
            ->postJson('/api/devis', ['client_id' => $client->id, 'titre' => 'Sol', 'portee' => 'entreprise'])
            ->assertCreated()->json('id');
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function ajouter(int $devis, int $article, ?string $niveau = null)
    {
        return $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes/depuis-article", array_filter([
            'article_prix_id' => $article, 'quantite' => 1, 'niveau_prestation' => $niveau,
        ]));
    }

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function regler(int $devis, array $donnees, ?User $qui = null)
    {
        return $this->agirEn($qui ?? $this->commercial)->putJson("/api/devis/{$devis}/parametres", $donnees);
    }

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function recalculer(int $devis, array $donnees = [])
    {
        return $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/recalculer", $donnees);
    }

    private function ligne(int $id): LigneDevis
    {
        return $this->chez(fn () => LigneDevis::query()->with('composants')->findOrFail($id));
    }

    public function test_le_niveau_est_un_reglage_de_l_entreprise_copie_par_le_devis(): void
    {
        $reglages = $this->agirEn($this->direction)->getJson('/api/parametres')->assertOk();
        $this->assertStringContainsString('niveau_prestation_defaut', (string) $reglages->getContent());

        $devis = $this->nouveauDevis();
        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->assertOk()->assertJsonPath('parametres.niveau_prestation', 'standard');

        $this->agirEn($this->direction)->putJson('/api/parametres/niveau_prestation_defaut', ['valeur' => 'luxe'])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson('/api/parametres/niveau_prestation_defaut', ['valeur' => 'haut_de_gamme'])->assertOk();

        // Un devis copie le réglage à sa création ; un devis existant ne bouge pas.
        $nouveau = $this->nouveauDevis();
        $this->agirEn($this->commercial)->getJson("/api/devis/{$nouveau}/parametres")->assertOk()->assertJsonPath('parametres.niveau_prestation', 'haut_de_gamme');
        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->assertOk()->assertJsonPath('parametres.niveau_prestation', 'standard');

        // La ligne suit : 90 + 2 x 300 = 690.
        $this->assertEqualsWithDelta(690, (float) $this->ajouter($nouveau, $this->article())->assertCreated()->json('prix_unitaire_ht'), 0.001);
    }

    public function test_la_ligne_est_copiee_au_niveau_du_devis(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();

        $premiere = $this->ajouter($devis, $article)->assertCreated()
            ->assertJsonPath('niveau_prestation', 'standard')
            ->assertJsonPath('niveau_prestation_fixe', false)
            ->assertJsonPath('composants.1.fourniture_id', $this->standard)
            // La ligne garde la fourniture standard et les deux variantes.
            ->assertJsonPath('composants.1.fourniture_standard_id', $this->standard)
            ->assertJsonPath('composants.1.fourniture_eco_id', $this->eco)
            ->assertJsonPath('composants.1.fourniture_haut_id', $this->haut);
        $this->assertEqualsWithDelta(390, (float) $premiere->json('prix_unitaire_ht'), 0.001);

        $this->regler($devis, ['niveau_prestation' => 'haut_de_gamme'])->assertOk()->assertJsonPath('parametres.niveau_prestation', 'haut_de_gamme');
        $this->regler($devis, ['niveau_prestation' => 'luxe'])->assertUnprocessable()->assertJsonValidationErrors('niveau_prestation');

        // Seules les nouvelles lignes suivent : 90 + 2 x 300 = 690, revient 60 + 2 x 180 = 420.
        $seconde = $this->ajouter($devis, $article)->assertCreated()
            ->assertJsonPath('niveau_prestation', 'haut_de_gamme')
            ->assertJsonPath('composants.1.fourniture_id', $this->haut)
            ->assertJsonPath('composants.1.designation', 'Carrelage haut de gamme')
            ->assertJsonPath('composants.1.fourniture_standard_id', $this->standard);
        $this->assertEqualsWithDelta(690, (float) $seconde->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(420, (float) $seconde->json('prix_revient_unitaire'), 0.001);
        $this->assertEqualsWithDelta(300, (float) $seconde->json('composants.1.prix_vente_ht'), 0.0001);
        $this->assertEqualsWithDelta(390, (float) $this->ligne($premiere->json('id'))->prix_unitaire_ht, 0.001);

        // Le changement est journalisé.
        $trace = $this->chez(fn () => Activity::query()->where('event', 'parametres_devis_modifies')->latest('id')->first());
        $this->assertSame('standard', $trace->properties['avant']['niveau_prestation']);
        $this->assertSame('haut_de_gamme', $trace->properties['apres']['niveau_prestation']);
    }

    public function test_le_niveau_se_choisit_pour_une_ligne(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();

        // Devis au niveau standard, ligne en économique : 90 + 2 x 80 = 250.
        $ligne = $this->ajouter($devis, $article, 'economique')->assertCreated()
            ->assertJsonPath('niveau_prestation', 'economique')
            ->assertJsonPath('niveau_prestation_fixe', true)
            ->assertJsonPath('composants.1.fourniture_id', $this->eco)
            ->assertJsonPath('composants.1.designation', 'Carrelage économique');
        $this->assertEqualsWithDelta(250, (float) $ligne->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(250, (float) $ligne->json('totaux_devis.total_ht'), 0.001);

        $this->ajouter($devis, $article, 'luxe')->assertUnprocessable()->assertJsonValidationErrors('niveau_prestation');
    }

    public function test_sans_variante_la_ligne_garde_la_fourniture_standard(): void
    {
        $article = $this->article(['fourniture_eco_id' => $this->eco]);
        $devis = $this->nouveauDevis();

        $ligne = $this->ajouter($devis, $article, 'haut_de_gamme')->assertCreated()
            ->assertJsonPath('niveau_prestation', 'haut_de_gamme')
            ->assertJsonPath('composants.1.fourniture_id', $this->standard)
            ->assertJsonPath('composants.1.fourniture_haut_id', null);
        $this->assertEqualsWithDelta(390, (float) $ligne->json('prix_unitaire_ht'), 0.001);
    }

    public function test_un_article_a_prix_force_suit_l_ecart_des_fournitures(): void
    {
        $article = $this->article();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}", ['prix_force_ht' => 400])->assertOk();
        $devis = $this->nouveauDevis();

        $standard = $this->ajouter($devis, $article)->assertCreated()->assertJsonPath('prix_force', true);
        $this->assertEqualsWithDelta(400, (float) $standard->json('prix_unitaire_ht'), 0.001);

        // Économique : 400 - (390 - 250) = 260. Haut de gamme : 400 + (690 - 390) = 700.
        $eco = $this->ajouter($devis, $article, 'economique')->assertCreated()->assertJsonPath('prix_force', true);
        $this->assertEqualsWithDelta(260, (float) $eco->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(150, (float) $eco->json('prix_revient_unitaire'), 0.001);
        $this->assertEqualsWithDelta(700, (float) $this->ajouter($devis, $article, 'haut_de_gamme')->assertCreated()->json('prix_unitaire_ht'), 0.001);
    }

    public function test_recalculer_applique_le_niveau_du_devis_sauf_aux_lignes_choisies(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();
        $suit = $this->ajouter($devis, $article)->assertCreated()->json('id');
        $choisie = $this->ajouter($devis, $article, 'economique')->assertCreated()->json('id');

        $this->regler($devis, ['niveau_prestation' => 'haut_de_gamme'])->assertOk();

        // La simulation compte sans rien écrire : 390 + 250 = 640, puis 690 + 250 = 940.
        $simulation = $this->recalculer($devis, ['simulation' => true])->assertOk()->assertJsonPath('fournitures_changees', 1);
        $this->assertEqualsWithDelta(640, (float) $simulation->json('total_ht_avant'), 0.001);
        $this->assertEqualsWithDelta(940, (float) $simulation->json('total_ht_apres'), 0.001);
        $this->assertEqualsWithDelta(390, (float) $this->ligne($suit)->prix_unitaire_ht, 0.001);
        $this->assertSame($this->standard, (int) $this->ligne($suit)->composants[1]->fourniture_id);

        $this->recalculer($devis)->assertOk()->assertJsonPath('lignes_recalculees', 2)->assertJsonPath('fournitures_changees', 1);
        $ligne = $this->ligne($suit);
        $this->assertEqualsWithDelta(690, (float) $ligne->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(420, (float) $ligne->prix_revient_unitaire, 0.001);
        $this->assertSame('haut_de_gamme', $ligne->niveau_prestation);
        $this->assertSame($this->haut, (int) $ligne->composants[1]->fourniture_id);
        $this->assertSame('Carrelage haut de gamme', $ligne->composants[1]->designation);
        // La ligne dont le niveau a été choisi ne bouge pas.
        $this->assertEqualsWithDelta(250, (float) $this->ligne($choisie)->prix_unitaire_ht, 0.001);
        $this->assertSame('economique', $this->ligne($choisie)->niveau_prestation);
        $this->assertSame($this->eco, (int) $this->ligne($choisie)->composants[1]->fourniture_id);

        // Un second recalcul ne change plus rien.
        $this->recalculer($devis)->assertOk()->assertJsonPath('fournitures_changees', 0)->assertJsonPath('lignes_modifiees', 0);

        // Retour au standard.
        $this->regler($devis, ['niveau_prestation' => 'standard'])->assertOk();
        $this->recalculer($devis)->assertOk()->assertJsonPath('fournitures_changees', 1);
        $this->assertEqualsWithDelta(390, (float) $this->ligne($suit)->prix_unitaire_ht, 0.001);
        $this->assertSame($this->standard, (int) $this->ligne($suit)->composants[1]->fourniture_id);
    }

    public function test_une_fourniture_qui_change_de_gamme_prend_le_prix_actuel_de_la_base(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $article)->assertCreated()->json('id');

        // Les prix de la Base montent après la copie.
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$this->standard}", ['prix_vente_ht' => 160])->assertOk();
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$this->haut}", ['prix_vente_ht' => 320])->assertOk();

        // Sans changement de niveau, la ligne garde son prix figé.
        $this->recalculer($devis)->assertOk()->assertJsonPath('fournitures_changees', 0);
        $this->assertEqualsWithDelta(390, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);

        // Passage en haut de gamme : c'est un autre produit, au prix du jour. 90 + 2 x 320 = 730.
        $this->regler($devis, ['niveau_prestation' => 'haut_de_gamme'])->assertOk();
        $this->recalculer($devis)->assertOk()->assertJsonPath('fournitures_changees', 1);
        $this->assertEqualsWithDelta(730, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);

        // Retour au standard : là aussi au prix du jour. 90 + 2 x 160 = 410.
        $this->regler($devis, ['niveau_prestation' => 'standard'])->assertOk();
        $this->recalculer($devis)->assertOk();
        $this->assertEqualsWithDelta(410, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);
    }

    public function test_une_ligne_a_prix_force_garde_son_niveau_au_recalcul(): void
    {
        $article = $this->article();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$article}", ['prix_force_ht' => 400])->assertOk();
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $article)->assertCreated()->json('id');
        $this->regler($devis, ['niveau_prestation' => 'haut_de_gamme'])->assertOk();

        $this->recalculer($devis)->assertOk()->assertJsonPath('lignes_prix_force_conservees', 1)->assertJsonPath('fournitures_changees', 0);
        $this->assertEqualsWithDelta(400, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);
        $this->assertSame('standard', $this->ligne($ligne)->niveau_prestation);

        // Si on demande de recalculer aussi les prix forcés, la ligne suit le sous-détail au niveau du devis.
        $this->recalculer($devis, ['inclure_prix_forces' => true])->assertOk()->assertJsonPath('fournitures_changees', 1);
        $this->assertEqualsWithDelta(690, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);
        $this->assertSame('haut_de_gamme', $this->ligne($ligne)->niveau_prestation);
    }

    public function test_une_variante_a_prix_calcule_suit_la_marge_du_devis(): void
    {
        $this->chez(fn () => Parametre::ecrire('marge_fournitures_defaut', 20));
        // Variante sans prix de vente saisi : achat 180, vente calculée 180 / 0,8 = 225.
        $calculee = $this->fourniture('CAR-CAL', 'Carrelage calculé', 200, null);
        $article = $this->article(['fourniture_haut_id' => $calculee]);
        $devis = $this->nouveauDevis();
        $this->regler($devis, ['niveau_prestation' => 'haut_de_gamme', 'marge_fournitures' => 30])->assertOk();

        // 180 / 0,7 = 257,1429 ; 90 + 2 x 257,1429 = 604,29.
        $ligne = $this->ajouter($devis, $article)->assertCreated()
            ->assertJsonPath('composants.1.fourniture_id', $calculee)
            ->assertJsonPath('composants.1.prix_vente_calcule', true);
        $this->assertEqualsWithDelta(604.29, (float) $ligne->json('prix_unitaire_ht'), 0.001);

        // Au niveau standard, le carrelage a un prix saisi : la marge du devis ne le touche pas.
        $standard = $this->ajouter($devis, $article, 'standard')->assertCreated()->assertJsonPath('composants.1.prix_vente_calcule', false);
        $this->assertEqualsWithDelta(390, (float) $standard->json('prix_unitaire_ht'), 0.001);
    }

    public function test_les_droits_et_les_devis_figes(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();

        // Régler le niveau du devis : Direction et Commercial.
        $this->regler($devis, ['niveau_prestation' => 'economique'], $this->creerUtilisateur($this->entreprise, 'Conducteur travaux'))->assertForbidden();
        $this->regler($devis, ['niveau_prestation' => 'economique'], $this->direction)->assertOk();
        // Le devis d'une autre entreprise est introuvable.
        $this->regler($devis, ['niveau_prestation' => 'standard'], $this->creerUtilisateur($this->creerEntreprise(), 'Direction'))->assertNotFound();

        // Un devis envoyé ne se règle plus et ne reçoit plus de ligne.
        $this->chez(fn () => Devis::query()->findOrFail($devis)->forceFill(['statut' => 'envoye'])->save());
        $this->regler($devis, ['niveau_prestation' => 'standard'])->assertUnprocessable();
        $this->ajouter($devis, $article, 'standard')->assertUnprocessable();
    }

    public function test_un_article_est_sous_le_seuil_des_qu_un_niveau_l_est(): void
    {
        // Haut de gamme vendu avec une marge trop faible : achat 180, vente 190.
        $fin = $this->fourniture('CAR-FIN', 'Carrelage fin', 200, 190);
        $article = $this->article(['fourniture_eco_id' => $this->eco, 'fourniture_haut_id' => $fin]);
        $sain = $this->article();
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_fournitures_min', ['valeur' => 20])->assertOk();
        foreach ([$article, $sain] as $id) {
            $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 400])->assertOk();
        }

        // Le niveau standard respecte le seuil, pas le haut de gamme (480 pour un minimum de 510).
        $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}")->assertOk()
            ->assertJsonPath('sous_seuil', false)
            ->assertJsonPath('niveaux_sous_seuil', ['haut_de_gamme']);
        $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$sain}")->assertOk()->assertJsonPath('niveaux_sous_seuil', []);

        // Le filtre de la liste retrouve l'article.
        $this->agirEn($this->direction)->getJson('/api/bibliotheque-prix?sous_seuil=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $article);

        // La modification de la variante le signale.
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$fin}", ['designation' => 'Carrelage fin poli'])->assertOk()
            ->assertJsonCount(1, 'articles_sous_seuil')
            ->assertJsonPath('articles_sous_seuil.0.id', $article)
            ->assertJsonPath('articles_sous_seuil.0.niveaux_sous_seuil', ['haut_de_gamme']);
        // Une fourniture qui n'est pas en cause ne signale rien.
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$this->haut}", ['designation' => 'Carrelage haut de gamme poli'])->assertOk()
            ->assertJsonPath('articles_sous_seuil', []);

        // Le bilan d'un import de catalogue le signale aussi : la variante passe de 200 à 210.
        $import = $this->agirEn($this->direction)->post('/api/imports-fournitures', [
            'fichier' => UploadedFile::fake()->createWithContent('catalogue.csv', "Ref;Tarif\nCAR-FIN;210\n"),
            'fournisseur_id' => $this->fournisseur, 'mode' => 'prix',
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->agirEn($this->direction)->putJson("/api/imports-fournitures/{$import}/correspondance", ['correspondance' => ['reference' => 0, 'prix_tarif_ht' => 1]])->assertOk();
        $this->agirEn($this->direction)->postJson("/api/imports-fournitures/{$import}/appliquer")->assertOk()
            ->assertJsonPath('bilan.modifiees', 1)
            ->assertJsonPath('bilan.articles_mis_a_jour', 1)
            ->assertJsonPath('bilan.articles_sous_seuil', [$article]);
    }
}
