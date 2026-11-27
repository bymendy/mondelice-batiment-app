<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\LigneDevis;
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
 * Tâche 3.6 bis, lot F4c : changer le niveau de prestation d'une ligne déjà
 * écrite, et recalcul du devis qui prévient des lignes au niveau choisi.
 *
 * Jeu d'essai : main-d'œuvre 1,5 h (vente 90, revient 60) et 2 m2 de
 * carrelage. Standard : achat 90, vente 150. Économique : achat 45, vente 80.
 * Haut de gamme : achat 180, vente 300. Prix de l'article : 250 / 390 / 690.
 */
class NiveauPrestationF4cTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    private User $commercial;

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
        $this->commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $this->chez(fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60, 'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
        ]));

        $fournisseur = $this->agirEn($this->direction)
            ->postJson('/api/fournisseurs', ['nom' => 'Point P', 'remise_defaut_pct' => 10])->assertCreated()->json('id');
        $creer = fn (string $reference, string $designation, float $tarif, float $vente): int => $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'fournisseur_id' => $fournisseur, 'reference' => $reference, 'designation' => $designation,
            'unite' => 'm2', 'prix_tarif_ht' => $tarif, 'prix_vente_ht' => $vente,
        ])->assertCreated()->json('id');
        $this->standard = $creer('CAR-STD', 'Carrelage standard', 100, 150);
        $this->eco = $creer('CAR-ECO', 'Carrelage économique', 50, 80);
        $this->haut = $creer('CAR-HDG', 'Carrelage haut de gamme', 200, 300);
    }

    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    private function article(): int
    {
        $id = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-F4C-' . uniqid(), 'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5,
        ])->assertCreated();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'fourniture_base', 'fourniture_id' => $this->standard, 'quantite' => 2,
            'fourniture_eco_id' => $this->eco, 'fourniture_haut_id' => $this->haut,
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

    private function ajouter(int $devis, int $article, ?string $niveau = null): int
    {
        return $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes/depuis-article", array_filter([
            'article_prix_id' => $article, 'quantite' => 1, 'niveau_prestation' => $niveau,
        ]))->assertCreated()->json('id');
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function changer(int $ligne, ?string $niveau, ?User $qui = null)
    {
        return $this->agirEn($qui ?? $this->commercial)->putJson("/api/lignes-devis/{$ligne}/niveau-prestation", ['niveau_prestation' => $niveau]);
    }

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function regler(int $devis, array $donnees)
    {
        return $this->agirEn($this->commercial)->putJson("/api/devis/{$devis}/parametres", $donnees);
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

    public function test_le_niveau_d_une_ligne_se_change_apres_coup(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article());

        // 390 -> 250 : seul le carrelage change, la main-d'œuvre reste.
        $reponse = $this->changer($ligne, 'economique')->assertOk()
            ->assertJsonPath('niveau_prestation', 'economique')
            ->assertJsonPath('niveau_prestation_fixe', true)
            ->assertJsonPath('fournitures_changees', 1)
            ->assertJsonPath('composants.1.fourniture_id', $this->eco)
            ->assertJsonPath('composants.1.designation', 'Carrelage économique')
            ->assertJsonPath('composants.0.type', 'main_oeuvre');
        $this->assertEqualsWithDelta(250, (float) $reponse->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(150, (float) $reponse->json('prix_revient_unitaire'), 0.001);
        $this->assertEqualsWithDelta(250, (float) $reponse->json('totaux_devis.total_ht'), 0.001);

        // Redemander le même niveau ne change rien.
        $this->changer($ligne, 'economique')->assertOk()->assertJsonPath('fournitures_changees', 0);
        $this->assertEqualsWithDelta(250, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);

        // Le changement est journalisé sur le devis.
        $trace = $this->chez(fn () => Activity::query()->where('event', 'niveau_ligne_modifie')->oldest('id')->first());
        $this->assertSame($ligne, $trace->properties['ligne_id']);
        $this->assertSame('standard', $trace->properties['avant']['niveau_prestation']);
        $this->assertSame('economique', $trace->properties['apres']['niveau_prestation']);
    }

    public function test_une_ligne_peut_suivre_de_nouveau_le_devis(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article(), 'economique');
        $this->regler($devis, ['niveau_prestation' => 'haut_de_gamme'])->assertOk();

        // Sans niveau, la ligne reprend celui du devis : 690.
        $reponse = $this->changer($ligne, null)->assertOk()
            ->assertJsonPath('niveau_prestation', 'haut_de_gamme')
            ->assertJsonPath('niveau_prestation_fixe', false)
            ->assertJsonPath('composants.1.fourniture_id', $this->haut);
        $this->assertEqualsWithDelta(690, (float) $reponse->json('prix_unitaire_ht'), 0.001);

        // Elle suit maintenant les recalculs.
        $this->regler($devis, ['niveau_prestation' => 'standard'])->assertOk();
        $this->recalculer($devis)->assertOk()->assertJsonPath('lignes_niveau_choisi_conservees', 0);
        $this->assertEqualsWithDelta(390, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);
    }

    public function test_la_fourniture_qui_change_prend_le_prix_actuel_de_la_base(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article());
        $this->agirEn($this->direction)->putJson("/api/fournitures/{$this->eco}", ['prix_vente_ht' => 90])->assertOk();

        // 90 + 2 x 90 = 270.
        $this->assertEqualsWithDelta(270, (float) $this->changer($ligne, 'economique')->assertOk()->json('prix_unitaire_ht'), 0.001);
    }

    public function test_une_ligne_a_prix_force_garde_son_prix_plus_l_ecart(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article());
        // Prix changé à la main : 500 au lieu de 390.
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligne}", ['prix_unitaire_ht' => 500])->assertOk()->assertJsonPath('prix_force', true);

        // Économique : 500 - (390 - 250) = 360. La ligne reste à prix forcé.
        $reponse = $this->changer($ligne, 'economique')->assertOk()->assertJsonPath('prix_force', true);
        $this->assertEqualsWithDelta(360, (float) $reponse->json('prix_unitaire_ht'), 0.001);
        // Haut de gamme : 360 + (690 - 250) = 800.
        $this->assertEqualsWithDelta(800, (float) $this->changer($ligne, 'haut_de_gamme')->assertOk()->json('prix_unitaire_ht'), 0.001);
        // Retour au standard : 500.
        $this->assertEqualsWithDelta(500, (float) $this->changer($ligne, 'standard')->assertOk()->json('prix_unitaire_ht'), 0.001);
    }

    public function test_les_refus(): void
    {
        $devis = $this->nouveauDevis();
        $ligne = $this->ajouter($devis, $this->article());

        $this->changer($ligne, 'luxe')->assertUnprocessable()->assertJsonValidationErrors('niveau_prestation');
        $this->agirEn($this->commercial)->putJson("/api/lignes-devis/{$ligne}/niveau-prestation", [])->assertUnprocessable()->assertJsonValidationErrors('niveau_prestation');

        // Une ligne libre n'a pas de niveau.
        $libre = $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/lignes", [
            'libelle' => 'Déplacement', 'quantite' => 1, 'prix_unitaire_ht' => 50,
        ])->assertCreated()->json('id');
        $this->changer($libre, 'economique')->assertUnprocessable()->assertJsonValidationErrors('niveau_prestation');

        // Sans le droit de modifier un devis.
        Role::firstOrCreate(['name' => 'Lecteur devis', 'guard_name' => 'web'])->syncPermissions(['devis.voir']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->changer($ligne, 'economique', $this->creerUtilisateur($this->entreprise, 'Lecteur devis'))->assertForbidden();
        // La ligne d'une autre entreprise est introuvable.
        $this->changer($ligne, 'economique', $this->creerUtilisateur($this->creerEntreprise(), 'Direction'))->assertNotFound();

        // Un devis envoyé ne se modifie plus.
        $this->chez(fn () => Devis::query()->findOrFail($devis)->forceFill(['statut' => 'envoye'])->save());
        $this->changer($ligne, 'economique')->assertUnprocessable();
        $this->assertEqualsWithDelta(390, (float) $this->ligne($ligne)->prix_unitaire_ht, 0.001);

        // Sans connexion.
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/lignes-devis/{$ligne}/niveau-prestation", ['niveau_prestation' => 'economique'])->assertUnauthorized();
    }

    public function test_le_recalcul_previent_des_lignes_au_niveau_choisi(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();
        $suit = $this->ajouter($devis, $article);
        $choisie = $this->ajouter($devis, $article, 'haut_de_gamme');
        // Une ligne dont le niveau choisi est celui du devis n'est pas signalée.
        $this->ajouter($devis, $article, 'economique');
        $this->regler($devis, ['niveau_prestation' => 'economique'])->assertOk();

        // La simulation l'annonce avant de confirmer.
        $simulation = $this->recalculer($devis, ['simulation' => true])->assertOk()->assertJsonPath('lignes_niveau_choisi_conservees', 1);
        $this->assertStringContainsString('3 lignes recalculées', $simulation->json('message'));
        $this->assertStringContainsString('1 ligne garde son niveau choisi à la main', $simulation->json('message'));

        $reponse = $this->recalculer($devis)->assertOk()->assertJsonPath('lignes_niveau_choisi_conservees', 1)->assertJsonPath('fournitures_changees', 1);
        $this->assertStringContainsString('1 ligne garde son niveau choisi à la main', $reponse->json('message'));
        $this->assertEqualsWithDelta(250, (float) $this->ligne($suit)->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(690, (float) $this->ligne($choisie)->prix_unitaire_ht, 0.001);

        // Deux lignes concernées : le message passe au pluriel.
        $this->changer($suit, 'standard')->assertOk();
        $pluriel = $this->recalculer($devis, ['simulation' => true])->assertOk()->assertJsonPath('lignes_niveau_choisi_conservees', 2);
        $this->assertStringContainsString('2 lignes gardent leur niveau choisi à la main', $pluriel->json('message'));

        // Sans ligne concernée, le message ne change pas.
        $autre = $this->nouveauDevis();
        $this->ajouter($autre, $article);
        $simple = $this->recalculer($autre)->assertOk()->assertJsonPath('lignes_niveau_choisi_conservees', 0);
        $this->assertSame('1 ligne recalculée.', $simple->json('message'));
    }

    public function test_les_parametres_du_devis_montrent_les_lignes_au_niveau_choisi(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();
        $this->ajouter($devis, $article);
        $choisie = $this->ajouter($devis, $article, 'haut_de_gamme');

        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->assertOk()
            ->assertJsonPath('parametres.niveau_prestation', 'standard')
            ->assertJsonCount(1, 'lignes_niveau_choisi')
            ->assertJsonPath('lignes_niveau_choisi.0.id', $choisie)
            ->assertJsonPath('lignes_niveau_choisi.0.libelle', 'Pose de carrelage')
            ->assertJsonPath('lignes_niveau_choisi.0.niveau_prestation', 'haut_de_gamme')
            ->assertJsonPath('lignes_niveau_choisi.0.differe_du_devis', true);

        $this->changer($choisie, null)->assertOk();
        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->assertOk()->assertJsonCount(0, 'lignes_niveau_choisi');
    }
}
