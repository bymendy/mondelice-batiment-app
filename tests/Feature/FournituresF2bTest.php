<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LigneDevis;
use App\Models\Parametre;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tâche 3.6 bis, lot F2b : marge des fournitures par devis.
 */
class FournituresF2bTest extends TestCase
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
        $this->reglage('marge_fournitures_defaut', 20);
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
     * Article : 1,5 h d'ouvrier (vente 90, revient 60), 2 fournitures à prix
     * CALCULÉ (achat 100) et 1 fourniture à prix SAISI (achat 100, vente 150).
     *
     * @return int
     */
    private function article(): int
    {
        $calculee = $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'designation' => 'Carrelage', 'unite' => 'm2', 'prix_achat_ht' => 100,
        ])->assertCreated()->assertJsonPath('prix_vente_saisi', false)->json('id');
        $saisie = $this->agirEn($this->direction)->postJson('/api/fournitures', [
            'designation' => 'Plinthe', 'unite' => 'ml', 'prix_achat_ht' => 100, 'prix_vente_ht' => 150,
        ])->assertCreated()->json('id');

        $id = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-F2B-' . uniqid(), 'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');
        foreach ([
            ['type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5],
            ['type' => 'fourniture_base', 'fourniture_id' => $calculee, 'quantite' => 2],
            ['type' => 'fourniture_base', 'fourniture_id' => $saisie, 'quantite' => 1],
        ] as $ligne) {
            $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", $ligne)->assertCreated();
        }

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
    private function ajouter(int $devis, int $article)
    {
        return $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 1])
            ->assertCreated();
    }

    /**
     * @param array<string, mixed> $donnees
     * @return \Illuminate\Testing\TestResponse
     */
    private function regler(int $devis, array $donnees, ?User $qui = null)
    {
        return $this->agirEn($qui ?? $this->commercial)->putJson("/api/devis/{$devis}/parametres", $donnees);
    }

    private function prixLigne(int $ligne): float
    {
        return (float) $this->chez(fn () => LigneDevis::query()->findOrFail($ligne)->prix_unitaire_ht);
    }

    public function test_sans_reglage_le_devis_reprend_les_prix_de_la_bibliotheque(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();

        $parametres = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")
            ->assertOk()->assertJsonPath('parametres.marge_fournitures_source', 'entreprise');
        $this->assertEqualsWithDelta(20, (float) $parametres->json('parametres.marge_fournitures'), 0.001);

        // 100 / 0,8 = 125 : 90 + 2 x 125 + 150 = 490 ; revient 60 + 200 + 100 = 360.
        $ligne = $this->ajouter($devis, $article)
            ->assertJsonPath('composants.1.prix_vente_calcule', true)
            ->assertJsonPath('composants.2.prix_vente_calcule', false)
            ->assertJsonPath('composants.0.prix_vente_calcule', false);
        $this->assertEqualsWithDelta(490, (float) $ligne->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(360, (float) $ligne->json('prix_revient_unitaire'), 0.001);
        $this->assertEqualsWithDelta(125, (float) $ligne->json('composants.1.prix_vente_ht'), 0.0001);
    }

    public function test_la_marge_du_devis_ne_touche_que_les_fournitures_a_prix_calcule(): void
    {
        $article = $this->article();
        $devis = $this->nouveauDevis();
        $avant = $this->ajouter($devis, $article)->json('id');

        $reponse = $this->regler($devis, ['marge_fournitures' => 30])
            ->assertOk()->assertJsonPath('parametres.marge_fournitures_source', 'modifie');
        $this->assertEqualsWithDelta(30, (float) $reponse->json('parametres.marge_fournitures'), 0.001);

        // Nouvelle ligne : 100 / 0,7 = 142,8571 ; 90 + 285,71 + 150 = 525,71. La plinthe (prix saisi) reste à 150.
        $nouvelle = $this->ajouter($devis, $article);
        $this->assertEqualsWithDelta(525.71, (float) $nouvelle->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(360, (float) $nouvelle->json('prix_revient_unitaire'), 0.001);
        $this->assertEqualsWithDelta(142.8571, (float) $nouvelle->json('composants.1.prix_vente_ht'), 0.0001);
        $this->assertEqualsWithDelta(150, (float) $nouvelle->json('composants.2.prix_vente_ht'), 0.0001);

        // La ligne déjà écrite ne bouge pas, la bibliothèque non plus.
        $this->assertEqualsWithDelta(490, $this->prixLigne($avant), 0.001);
        $this->assertEqualsWithDelta(490, (float) $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$article}")->json('prix_vente_ht'), 0.001);

        // « Recalculer le devis » applique la marge du devis à l'ancienne ligne.
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/recalculer")
            ->assertOk()->assertJsonPath('lignes_recalculees', 2)->assertJsonPath('lignes_modifiees', 1);
        $this->assertEqualsWithDelta(525.71, $this->prixLigne($avant), 0.001);

        // Revenir à la marge de l'entreprise remet la source et les prix.
        $this->regler($devis, ['marge_fournitures' => 20])->assertOk()->assertJsonPath('parametres.marge_fournitures_source', 'entreprise');
        $this->agirEn($this->commercial)->postJson("/api/devis/{$devis}/recalculer")->assertOk();
        $this->assertEqualsWithDelta(490, $this->prixLigne($avant), 0.001);

        // Un autre devis n'est pas concerné.
        $autre = $this->nouveauDevis();
        $this->assertEqualsWithDelta(490, (float) $this->ajouter($autre, $article)->json('prix_unitaire_ht'), 0.001);
    }

    public function test_la_marge_du_devis_se_combine_avec_ses_frais_generaux(): void
    {
        $this->reglage('frais_generaux_fournitures', 5);
        $article = $this->article();
        $devis = $this->nouveauDevis();

        // 105 / 0,8 = 131,25 : 90 + 262,50 + 150 = 502,50 ; revient 60 + 210 + 105 = 375.
        $ligne = $this->ajouter($devis, $article);
        $this->assertEqualsWithDelta(502.5, (float) $ligne->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(375, (float) $ligne->json('prix_revient_unitaire'), 0.001);

        // Marge du devis à 10 % : 105 / 0,9 = 116,6667 ; 90 + 233,33 + 150 = 473,33.
        $this->regler($devis, ['marge_fournitures' => 10])->assertOk();
        $this->assertEqualsWithDelta(473.33, (float) $this->ajouter($devis, $article)->json('prix_unitaire_ht'), 0.001);
    }

    public function test_un_devis_sans_marge_garde_les_prix_de_la_bibliotheque_puis_suit_son_reglage(): void
    {
        $article = $this->article();
        // L'entreprise retire sa marge par défaut : les prix déjà calculés restent en l'état.
        $this->reglage('marge_fournitures_defaut', null);
        $devis = $this->nouveauDevis();

        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->assertOk()->assertJsonPath('parametres.marge_fournitures', null);
        $this->assertEqualsWithDelta(490, (float) $this->ajouter($devis, $article)->json('prix_unitaire_ht'), 0.001);

        // 100 / 0,75 = 133,3333 : 90 + 266,67 + 150 = 506,67.
        $this->regler($devis, ['marge_fournitures' => 25])->assertOk()->assertJsonPath('parametres.marge_fournitures_source', 'modifie');
        $this->assertEqualsWithDelta(506.67, (float) $this->ajouter($devis, $article)->json('prix_unitaire_ht'), 0.001);
    }

    public function test_la_marge_du_devis_respecte_le_seuil_de_la_direction(): void
    {
        $this->agirEn($this->direction)->putJson('/api/parametres/seuils/marge_fournitures_min', ['valeur' => 15])->assertOk();
        $devis = $this->nouveauDevis();

        // Sous le seuil : refusé pour le Commercial, rien ne change.
        $this->regler($devis, ['marge_fournitures' => 10])->assertUnprocessable()->assertJsonValidationErrors('marge_fournitures');
        $vue = $this->agirEn($this->commercial)->getJson("/api/devis/{$devis}/parametres")->assertOk();
        $this->assertEqualsWithDelta(20, (float) $vue->json('parametres.marge_fournitures'), 0.001);

        // Au seuil : accepté. La Direction n'est pas bloquée.
        $this->regler($devis, ['marge_fournitures' => 15])->assertOk();
        $reponse = $this->regler($devis, ['marge_fournitures' => 10], $this->direction)->assertOk();
        $this->assertEqualsWithDelta(10, (float) $reponse->json('parametres.marge_fournitures'), 0.001);

        // Valeurs impossibles.
        foreach ([100, -1, 'beaucoup'] as $valeur) {
            $this->regler($devis, ['marge_fournitures' => $valeur])->assertUnprocessable()->assertJsonValidationErrors('marge_fournitures');
        }

        // Sans la permission de régler les paramètres du devis : refusé.
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->regler($devis, ['marge_fournitures' => 25], $conducteur)->assertForbidden();
    }

    public function test_le_changement_de_marge_est_journalise(): void
    {
        $devis = $this->nouveauDevis();

        // Même valeur : rien au journal.
        $this->regler($devis, ['marge_fournitures' => 20])->assertOk();
        $this->assertSame(0, $this->chez(fn () => Activity::query()->where('event', 'parametres_devis_modifies')->count()));

        $this->regler($devis, ['marge_fournitures' => 28.5])->assertOk();
        $trace = $this->chez(fn () => Activity::query()->where('event', 'parametres_devis_modifies')->firstOrFail());
        $this->assertSame($devis, (int) $trace->subject_id);
        $this->assertEqualsWithDelta(20, (float) $trace->properties['avant']['marge_fournitures'], 0.001);
        $this->assertEqualsWithDelta(28.5, (float) $trace->properties['apres']['marge_fournitures'], 0.001);
    }
}
