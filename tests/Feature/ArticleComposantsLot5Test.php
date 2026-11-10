<?php

namespace Tests\Feature;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\Parametre;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lot 5 de la Bibliotheque de prix : sous-detail des articles par l'API
 * (lecture, ajout, modification, suppression, reordonnancement), prix recalcule
 * a chaque ecriture, masquage des prix d'achat, droits alignes sur ceux de
 * l'article.
 */
class ArticleComposantsLot5Test extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    private User $direction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->dansEntreprise($this->entreprise, fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60,
            'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
        ]));
    }

    private function activerPourLeCommercial(): void
    {
        $this->dansEntreprise(
            $this->entreprise,
            fn () => Parametre::ecrire('commercial_peut_modifier_bibliotheque', true)
        );
    }

    /**
     * Article d'entreprise cree par la Direction.
     *
     * @param string $reference
     * @return int
     */
    private function article(string $reference = 'ART-SD'): int
    {
        return $this->agirEn($this->direction)
            ->postJson('/api/bibliotheque-prix', [
                'reference' => $reference, 'designation' => 'Pose de carrelage',
                'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
            ])
            ->assertCreated()
            ->json('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function mainOeuvre(float $quantite = 1.5): array
    {
        return ['type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => $quantite];
    }

    /**
     * @return array<string, mixed>
     */
    private function fourniture(): array
    {
        return [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 2,
            'unite' => 'u', 'prix_achat_ht' => 5, 'prix_vente_ht' => 8,
        ];
    }

    /**
     * Article de 1,5 h de main-d'oeuvre + 2 fournitures a 8 EUR (achat 5) :
     * vente 106, revient 70, marge 36.
     *
     * @return int
     */
    private function articleAvecSousDetail(): int
    {
        $id = $this->article();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", $this->mainOeuvre())->assertCreated();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())->assertCreated();

        return $id;
    }

    /**
     * @param \Illuminate\Testing\TestResponse $reponse
     * @param string $chemin
     * @param float|int|null $attendu
     * @return void
     */
    private function montant($reponse, string $chemin, float|int|null $attendu): void
    {
        $valeur = $reponse->json($chemin);

        if ($attendu === null) {
            $this->assertNull($valeur, $chemin);

            return;
        }

        $this->assertEqualsWithDelta($attendu, $valeur, 0.001, $chemin);
    }

    /**
     * Article de reference avec une ligne de main-d'oeuvre (2 h) et une fourniture.
     *
     * @return ArticlePrix
     */
    private function reference(): ArticlePrix
    {
        $reference = ArticlePrix::creerReference([
            'reference' => 'R-0001', 'designation' => 'Reference', 'type_article' => 'materiaux',
            'unite' => 'u', 'taux_tva' => 20, 'source' => 'mondelice',
        ]);
        ArticleComposant::creerReference([
            'article_id' => $reference->id, 'type' => 'main_oeuvre', 'qualification' => 'ouvrier',
            'designation' => 'Main d oeuvre', 'quantite' => 2, 'unite' => 'h', 'ordre' => 1,
        ]);
        ArticleComposant::creerReference([
            'article_id' => $reference->id, 'type' => 'fourniture_libre', 'designation' => 'Colle',
            'quantite' => 1, 'unite' => 'u', 'prix_achat_ht' => 3, 'prix_vente_ht' => 5, 'ordre' => 2,
        ]);

        return $reference;
    }

    #[Test]
    public function la_direction_construit_le_sous_detail_et_le_prix_se_recalcule(): void
    {
        $id = $this->article();

        $reponse = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->mainOeuvre())
            ->assertCreated()
            ->assertJsonPath('composant.unite', 'h')
            ->assertJsonPath('composant.designation', 'Ouvrier')
            ->assertJsonPath('composant.ordre', 1);
        $this->montant($reponse, 'composant.total_vente_ht', 90);
        $this->montant($reponse, 'composant.total_revient_ht', 60);
        $this->montant($reponse, 'article.prix_vente_ht', 90);

        $reponse = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())
            ->assertCreated()
            ->assertJsonPath('composant.ordre', 2);
        $this->montant($reponse, 'article.prix_vente_ht', 106);
        $this->montant($reponse, 'article.prix_revient_ht', 70);
        $this->montant($reponse, 'article.marge_ht', 36);

        $liste = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$id}/composants")->assertOk();
        $this->assertSame(['main_oeuvre', 'fourniture_libre'], collect($liste->json('data'))->pluck('type')->all());
        $this->montant($liste, 'article.prix_vente_ht', 106);
    }

    #[Test]
    public function une_ligne_de_main_d_oeuvre_garde_sa_designation_si_elle_en_a_une(): void
    {
        $id = $this->article();

        $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->mainOeuvre() + ['designation' => 'Pose soignee', 'unite' => 'h'])
            ->assertCreated()
            ->assertJsonPath('composant.designation', 'Pose soignee');
    }

    #[Test]
    public function les_prix_d_achat_sont_masques_sans_la_permission(): void
    {
        $id = $this->articleAvecSousDetail();
        $lecteur = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $lecteur->givePermissionTo('bibliotheque.consulter');

        $reponse = $this->agirEn($lecteur)->getJson("/api/bibliotheque-prix/{$id}/composants")->assertOk();
        $this->montant($reponse, 'data.0.total_vente_ht', 90);
        $this->montant($reponse, 'data.1.total_vente_ht', 16);
        foreach (['prix_achat_ht', 'prix_achat_unitaire_ht', 'total_revient_ht'] as $champ) {
            $reponse->assertJsonMissingPath("data.0.{$champ}");
            $reponse->assertJsonMissingPath("data.1.{$champ}");
        }
        $reponse->assertJsonMissingPath('article.marge_ht');

        $commercial = $this->agirEn($this->creerUtilisateur($this->entreprise, 'Commercial'))
            ->getJson("/api/bibliotheque-prix/{$id}/composants")->assertOk();
        $this->montant($commercial, 'data.0.total_revient_ht', 60);
        $this->montant($commercial, 'data.1.prix_achat_ht', 5);
        $this->montant($commercial, 'data.1.total_revient_ht', 10);
    }

    #[Test]
    public function les_lignes_invalides_sont_refusees(): void
    {
        $id = $this->article();
        $invalides = [
            'main d oeuvre sans qualification' => ['type' => 'main_oeuvre', 'quantite' => 1],
            'qualification inconnue' => ['type' => 'main_oeuvre', 'qualification' => 'inconnue', 'quantite' => 1],
            'main d oeuvre avec un prix' => $this->mainOeuvre() + ['prix_vente_ht' => 5],
            'main d oeuvre avec un prix d achat' => $this->mainOeuvre() + ['prix_achat_ht' => 5],
            'main d oeuvre en m2' => $this->mainOeuvre() + ['unite' => 'm2'],
            'fourniture sans prix de vente' => ['type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 1, 'unite' => 'u'],
            'fourniture sans designation' => ['type' => 'fourniture_libre', 'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 5],
            'fourniture sans unite' => ['type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 1, 'prix_vente_ht' => 5],
            'fourniture avec une qualification' => $this->fourniture() + ['qualification' => 'ouvrier'],
            'quantite negative' => ['quantite' => -1] + $this->fourniture(),
            'quantite absente' => ['type' => 'frais', 'designation' => 'Deplacement', 'unite' => 'u', 'prix_vente_ht' => 5],
            'prix negatif' => ['prix_vente_ht' => -5] + $this->fourniture(),
            'fourniture de la base pas encore disponible' => ['type' => 'fourniture_base'] + $this->fourniture(),
            'type inconnu' => ['type' => 'autre'] + $this->fourniture(),
            'sans type' => ['designation' => 'Colle', 'quantite' => 1],
        ];

        foreach ($invalides as $cas => $donnees) {
            $this->agirEn($this->direction)
                ->postJson("/api/bibliotheque-prix/{$id}/composants", $donnees)
                ->assertUnprocessable();
        }

        $this->assertSame(0, DB::table('article_composants')->where('article_id', $id)->count());
    }

    #[Test]
    public function on_modifie_une_ligne_mais_pas_son_type(): void
    {
        $id = $this->article();
        $ligne = $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())->json('composant.id');
        $mo = $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", $this->mainOeuvre(1))->json('composant.id');

        $reponse = $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}/composants/{$ligne}", ['quantite' => 3, 'prix_vente_ht' => 10, 'type' => 'frais'])
            ->assertOk()
            ->assertJsonPath('composant.type', 'fourniture_libre');
        $this->montant($reponse, 'composant.total_vente_ht', 30);
        $this->montant($reponse, 'article.prix_vente_ht', 90);

        // Main-d'oeuvre : on change les heures ou la qualification, jamais un prix.
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/{$mo}", ['prix_vente_ht' => 5])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/{$mo}", ['unite' => 'm2'])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/{$mo}", ['qualification' => 'inconnue'])->assertUnprocessable();
        $reponse = $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}/composants/{$mo}", ['quantite' => 2])
            ->assertOk();
        $this->montant($reponse, 'composant.total_vente_ht', 120);
        $this->montant($reponse, 'article.prix_vente_ht', 150);

        // Fourniture : un prix de vente ne se vide pas, un prix d'achat si.
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/{$ligne}", ['prix_vente_ht' => null])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/{$ligne}", ['prix_achat_ht' => null])->assertOk();
    }

    #[Test]
    public function on_supprime_une_ligne_et_une_ligne_d_un_autre_article_repond_404(): void
    {
        $a = $this->article('ART-A');
        $b = $this->article('ART-B');
        $ligneA = $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$a}/composants", $this->fourniture())->json('composant.id');
        $ligneB = $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$b}/composants", $this->fourniture())->json('composant.id');

        $this->agirEn($this->direction)->deleteJson("/api/bibliotheque-prix/{$a}/composants/{$ligneB}")->assertNotFound();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$a}/composants/{$ligneB}", ['quantite' => 9])->assertNotFound();
        $this->assertDatabaseHas('article_composants', ['id' => $ligneB]);

        $reponse = $this->agirEn($this->direction)->deleteJson("/api/bibliotheque-prix/{$a}/composants/{$ligneA}")->assertOk();
        $this->assertSame([], $reponse->json('data'));
        $this->montant($reponse, 'article.prix_vente_ht', 0);
        $this->assertDatabaseMissing('article_composants', ['id' => $ligneA]);
    }

    #[Test]
    public function on_reordonne_le_sous_detail(): void
    {
        $id = $this->article();
        $ids = [];
        foreach ([$this->mainOeuvre(), $this->fourniture(), ['designation' => 'Frais'] + $this->fourniture() + ['type' => 'frais']] as $ligne) {
            $ligne['type'] = $ligne['type'] ?? 'frais';
            $ids[] = $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", $ligne)->assertCreated()->json('composant.id');
        }
        [$a, $b, $c] = $ids;

        $reponse = $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}/composants/ordre", ['ids' => [$c, $a, $b]])
            ->assertOk();
        $this->assertSame([$c, $a, $b], collect($reponse->json('data'))->pluck('id')->all());
        $this->assertSame([1, 2, 3], collect($reponse->json('data'))->pluck('ordre')->all());

        // Liste incomplete, ligne etrangere ou doublon : refuses, l'ordre ne change pas.
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/ordre", ['ids' => [$a, $b]])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/ordre", ['ids' => [$a, $b, $c, 999999]])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/ordre", ['ids' => [$a, $a, $b]])->assertUnprocessable();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}/composants/ordre", [])->assertUnprocessable();

        $liste = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$id}/composants")->assertOk();
        $this->assertSame([$c, $a, $b], collect($liste->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function modifier_le_sous_detail_d_un_article_d_entreprise_demande_de_pouvoir_publier(): void
    {
        $this->activerPourLeCommercial();
        $id = $this->articleAvecSousDetail();
        $ligne = DB::table('article_composants')->where('article_id', $id)->value('id');

        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $this->agirEn($commercial)->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())->assertForbidden();
        $this->agirEn($commercial)->putJson("/api/bibliotheque-prix/{$id}/composants/{$ligne}", ['quantite' => 9])->assertForbidden();
        $this->agirEn($commercial)->deleteJson("/api/bibliotheque-prix/{$id}/composants/{$ligne}")->assertForbidden();
        $this->agirEn($commercial)->putJson("/api/bibliotheque-prix/{$id}/composants/ordre", ['ids' => [1]])->assertForbidden();
        $this->agirEn($commercial)->getJson("/api/bibliotheque-prix/{$id}/composants")->assertOk();

        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Administratif'))
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())
            ->assertForbidden();

        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Conducteur travaux'))
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())
            ->assertCreated();
    }

    #[Test]
    public function le_commercial_dont_le_reglage_est_desactive_ne_modifie_aucun_sous_detail(): void
    {
        $id = $this->article();

        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Commercial'))
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())
            ->assertForbidden();
    }

    #[Test]
    public function le_commercial_edite_le_sous_detail_de_sa_version_privee_sans_toucher_la_reference(): void
    {
        $this->activerPourLeCommercial();
        $reference = $this->reference();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $copie = $this->agirEn($commercial)
            ->postJson("/api/bibliotheque-prix/{$reference->id}/personnaliser")
            ->assertCreated()
            ->json('id');

        $this->agirEn($commercial)
            ->postJson("/api/bibliotheque-prix/{$copie}/composants", ['type' => 'frais', 'designation' => 'Deplacement', 'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 20])
            ->assertCreated();

        $this->assertSame(3, DB::table('article_composants')->where('article_id', $copie)->count());
        $this->assertSame(2, DB::table('article_composants')->where('article_id', $reference->id)->count());

        // La reference ne se modifie pas, pour personne.
        $this->agirEn($commercial)
            ->postJson("/api/bibliotheque-prix/{$reference->id}/composants", ['type' => 'frais', 'designation' => 'Intrus', 'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 1])
            ->assertForbidden();
        $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$reference->id}/composants", ['type' => 'frais', 'designation' => 'Intrus', 'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 1])
            ->assertForbidden();
        $ligneReference = DB::table('article_composants')->where('article_id', $reference->id)->value('id');
        $this->agirEn($this->direction)->deleteJson("/api/bibliotheque-prix/{$reference->id}/composants/{$ligneReference}")->assertForbidden();

        // Une version privee reste invisible des autres, sous-detail compris.
        $this->agirEn($collegue)->getJson("/api/bibliotheque-prix/{$copie}/composants")->assertNotFound();
        $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$copie}/composants")->assertNotFound();
        $this->agirEn($collegue)
            ->postJson("/api/bibliotheque-prix/{$copie}/composants", $this->fourniture())
            ->assertNotFound();
    }

    #[Test]
    public function le_sous_detail_d_une_reference_se_lit_avec_les_taux_de_l_entreprise(): void
    {
        $reference = $this->reference();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $reponse = $this->agirEn($commercial)->getJson("/api/bibliotheque-prix/{$reference->id}/composants")->assertOk();
        $this->assertCount(2, $reponse->json('data'));
        $this->montant($reponse, 'data.0.total_vente_ht', 120);
        $this->montant($reponse, 'data.0.total_revient_ht', 80);
        $this->montant($reponse, 'data.1.total_vente_ht', 5);
        $this->montant($reponse, 'article.prix_vente_ht', 125);

        // Une entreprise sans le taux « ouvrier » : erreur signalee, jamais de prix devine.
        $autre = $this->creerEntreprise();
        $reponse = $this->agirEn($this->creerUtilisateur($autre, 'Direction'))
            ->getJson("/api/bibliotheque-prix/{$reference->id}/composants")
            ->assertOk();
        $this->assertNotNull($reponse->json('data.0.erreur_prix'));
        $this->montant($reponse, 'data.0.total_vente_ht', null);
    }

    #[Test]
    public function une_entreprise_ne_voit_ni_ne_modifie_le_sous_detail_d_une_autre(): void
    {
        $id = $this->articleAvecSousDetail();
        $intrus = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');

        $this->agirEn($intrus)->getJson("/api/bibliotheque-prix/{$id}/composants")->assertNotFound();
        $this->agirEn($intrus)->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())->assertNotFound();
        $this->assertSame(2, DB::table('article_composants')->where('article_id', $id)->count());
    }
}
