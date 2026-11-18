<?php

namespace Tests\Feature;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\Client;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Module Devis, lot D3 : copie figee d'un article de la bibliotheque dans une
 * ligne de devis, avec son sous-detail.
 */
class LigneDevisArticleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;
    private User $direction;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
        $this->direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $this->chez(fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60,
            'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
        ]));
    }

    /**
     * @param callable $action
     * @return mixed
     */
    private function chez(callable $action): mixed
    {
        return $this->dansEntreprise($this->entreprise, $action);
    }

    /**
     * Devis d'entreprise en preparation, visible de tous.
     *
     * @return Devis
     */
    private function nouveauDevis(): Devis
    {
        return $this->chez(function (): Devis {
            $client = Client::create(['type' => 'particulier', 'nom' => 'Dupont']);

            return Devis::create([
                'client_id' => $client->id,
                'titre' => 'Salle de bain',
                'auteur_id' => $this->direction->id,
                'portee' => 'entreprise',
            ]);
        });
    }

    /**
     * Article de l'entreprise : 1,5 h d'ouvrier (vente 90, revient 60) et
     * 2 fournitures a 8 EUR (achat 5) : vente 106, revient 70, TVA 10 %.
     *
     * @return int
     */
    private function articleAvecSousDetail(): int
    {
        $id = $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'ART-D3', 'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
        ])->assertCreated()->json('id');

        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5,
        ])->assertCreated();
        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$id}/composants", [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 2,
            'unite' => 'u', 'prix_achat_ht' => 5, 'prix_vente_ht' => 8,
        ])->assertCreated();

        DB::table('articles_prix')->where('id', $id)->update([
            'description' => 'Fourniture et pose de carrelage',
            'dtu_reference' => 'NF DTU 52.2',
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $attributs
     * @return ArticlePrix
     */
    private function articleSimple(array $attributs = []): ArticlePrix
    {
        return $this->chez(fn () => ArticlePrix::create(array_merge([
            'reference' => 'SIMPLE-1', 'designation' => 'Article simple', 'type_article' => 'materiaux',
            'unite' => 'u', 'taux_tva' => 20, 'actif' => true, 'prix_force_ht' => 50,
        ], $attributs)));
    }

    public function test_la_ligne_copie_le_prix_et_le_sous_detail(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleAvecSousDetail();

        $reponse = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 3])
            ->assertCreated()
            ->assertJsonPath('libelle', 'Pose de carrelage')
            ->assertJsonPath('descriptif', 'Fourniture et pose de carrelage')
            ->assertJsonPath('dtu_reference', 'NF DTU 52.2')
            ->assertJsonPath('unite', 'm2')
            ->assertJsonPath('article_prix_id', $article)
            ->assertJsonCount(2, 'composants')
            ->assertJsonPath('composants.0.type', 'main_oeuvre')
            ->assertJsonPath('composants.0.taux_horaire_libelle', 'Ouvrier')
            ->assertJsonPath('composants.1.designation', 'Colle');

        $this->assertEqualsWithDelta(106, (float) $reponse->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(10, (float) $reponse->json('taux_tva'), 0.001);
        $this->assertEqualsWithDelta(60, (float) $reponse->json('composants.0.taux_horaire_vente'), 0.001);
        $this->assertEqualsWithDelta(1.5, (float) $reponse->json('composants.0.quantite'), 0.001);
        $this->assertEqualsWithDelta(318, (float) $reponse->json('totaux_devis.total_ht'), 0.001);
        $this->assertEqualsWithDelta(349.8, (float) $reponse->json('totaux_devis.total_ttc'), 0.001);

        $ligne = $this->chez(fn () => LigneDevis::query()->findOrFail($reponse->json('id')));
        $this->assertEqualsWithDelta(70, (float) $ligne->prix_revient_unitaire, 0.001);
        $this->assertSame($devis->id, $ligne->devis_id);
    }

    public function test_le_prix_de_revient_et_les_prix_d_achat_ne_sont_pas_renvoyes(): void
    {
        // Depuis le lot D4a : vrai seulement sans la permission devis.voir_marge.
        $roleSansMarge = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Sans marge', 'guard_name' => 'web']);
        $roleSansMarge->syncPermissions(['devis.voir', 'devis.modifier', 'bibliotheque.consulter']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->commercial = $this->creerUtilisateur($this->entreprise, 'Sans marge');
        $devis = $this->nouveauDevis();
        $article = $this->articleAvecSousDetail();

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 1])
            ->assertCreated()
            ->assertJsonMissingPath('prix_revient_unitaire')
            ->assertJsonMissingPath('composants.0.prix_achat_ht')
            ->assertJsonMissingPath('composants.0.cout_revient_horaire')
            ->assertJsonMissingPath('composants.1.prix_achat_ht');

        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")
            ->assertOk()
            ->assertJsonCount(1, 'lignes')
            ->assertJsonCount(2, 'lignes.0.composants')
            ->assertJsonMissingPath('lignes.0.prix_revient_unitaire')
            ->assertJsonMissingPath('lignes.0.composants.0.prix_achat_ht')
            ->assertJsonMissingPath('lignes.0.composants.0.cout_revient_horaire');

        $composants = $this->chez(fn () => LigneDevis::query()->firstOrFail()->composants);
        $this->assertEqualsWithDelta(40, (float) $composants[0]->cout_revient_horaire, 0.001);
        $this->assertEqualsWithDelta(5, (float) $composants[1]->prix_achat_ht, 0.001);
    }

    public function test_un_prix_force_prime_sur_le_sous_detail(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleAvecSousDetail();
        DB::table('articles_prix')->where('id', $article)->update(['prix_force_ht' => 99]);

        $reponse = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 1])
            ->assertCreated();

        $this->assertEqualsWithDelta(99, (float) $reponse->json('prix_unitaire_ht'), 0.001);
        $this->assertCount(2, $reponse->json('composants'));
    }

    public function test_la_ligne_reste_figee_quand_la_bibliotheque_ou_les_taux_changent(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleAvecSousDetail();

        $id = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 2])
            ->assertCreated()
            ->json('id');

        $this->chez(fn () => TauxHoraire::query()->where('code', 'ouvrier')->update(['taux_vente_ht' => 80, 'cout_revient_ht' => 55]));
        DB::table('articles_prix')->where('id', $article)->update(['prix_force_ht' => 500, 'designation' => 'Autre nom']);
        DB::table('article_composants')->where('article_id', $article)->delete();

        $ligne = $this->chez(fn () => LigneDevis::query()->findOrFail($id));
        $this->assertSame('Pose de carrelage', $ligne->libelle);
        $this->assertEqualsWithDelta(106, (float) $ligne->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(70, (float) $ligne->prix_revient_unitaire, 0.001);

        $composants = $this->chez(fn () => $ligne->composants);
        $this->assertCount(2, $composants);
        $this->assertEqualsWithDelta(60, (float) $composants[0]->taux_horaire_vente, 0.001);
        $this->assertEqualsWithDelta(40, (float) $composants[0]->cout_revient_horaire, 0.001);

        $this->agirEn($this->commercial)->getJson("/api/devis/{$devis->id}")
            ->assertOk()
            ->assertJsonPath('lignes.0.composants.0.taux_horaire_vente', '60.00');
    }

    public function test_une_qualification_inconnue_refuse_l_ajout(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleAvecSousDetail();
        $this->chez(fn () => TauxHoraire::query()->where('code', 'ouvrier')->delete());

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('article_prix_id');

        $this->assertSame(0, $this->chez(fn () => LigneDevis::query()->count()));
    }

    public function test_un_article_de_reference_se_copie(): void
    {
        $devis = $this->nouveauDevis();
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

        $reponse = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $reference->id, 'quantite' => 1])
            ->assertCreated()
            ->assertJsonCount(2, 'composants');

        $this->assertEqualsWithDelta(125, (float) $reponse->json('prix_unitaire_ht'), 0.001);
        $this->assertEqualsWithDelta(20, (float) $reponse->json('taux_tva'), 0.001);
    }

    public function test_la_version_privee_d_un_autre_est_introuvable(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleSimple();
        $this->chez(fn () => $article->forceFill(['portee' => 'prive', 'proprietaire_id' => $this->direction->id])->save());

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article->id, 'quantite' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('article_prix_id');

        $this->agirEn($this->direction)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article->id, 'quantite' => 1])
            ->assertCreated();
    }

    public function test_un_article_archive_ou_inactif_est_refuse(): void
    {
        $devis = $this->nouveauDevis();
        $inactif = $this->articleSimple(['reference' => 'INACTIF', 'actif' => false]);
        $archive = $this->articleSimple(['reference' => 'ARCHIVE']);
        $this->chez(fn () => $archive->delete());

        foreach ([$inactif->id, $archive->id, 999999] as $id) {
            $this->agirEn($this->commercial)
                ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $id, 'quantite' => 1])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('article_prix_id');
        }
    }

    public function test_les_droits_suivent_les_roles(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleSimple();
        $terrain = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');

        $this->agirEn($terrain)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article->id, 'quantite' => 1])
            ->assertForbidden();

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article->id, 'quantite' => 1])
            ->assertCreated();
    }

    public function test_un_visiteur_non_connecte_est_refuse(): void
    {
        $devis = $this->nouveauDevis();

        $this->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => 1, 'quantite' => 1])
            ->assertUnauthorized();
    }

    public function test_un_devis_envoye_n_est_plus_modifiable(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleSimple();
        $this->chez(fn () => $devis->forceFill(['statut' => 'envoye'])->save());

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article->id, 'quantite' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('devis');
    }

    public function test_les_champs_sont_controles(): void
    {
        $devis = $this->nouveauDevis();
        $autre = $this->nouveauDevis();
        $article = $this->articleSimple();

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['article_prix_id', 'quantite']);

        $lotAutre = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$autre->id}/lots", ['libelle' => 'Plomberie', 'type' => 'lot'])
            ->assertCreated()->json('id');

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", [
                'article_prix_id' => $article->id, 'quantite' => 1, 'lot_id' => $lotAutre,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lot_id');

        $lot = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lots", ['libelle' => 'Plomberie', 'type' => 'lot'])
            ->assertCreated()->json('id');

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", [
                'article_prix_id' => $article->id, 'quantite' => 1, 'lot_id' => $lot,
            ])
            ->assertCreated()
            ->assertJsonPath('lot_id', $lot);
    }

    public function test_supprimer_la_ligne_supprime_son_sous_detail(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleAvecSousDetail();

        $id = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 1])
            ->assertCreated()->json('id');
        $this->assertSame(2, DB::table('lignes_devis_composants')->where('ligne_devis_id', $id)->count());

        $this->agirEn($this->commercial)->deleteJson("/api/lignes-devis/{$id}")->assertOk();

        $this->assertSame(0, DB::table('lignes_devis_composants')->where('ligne_devis_id', $id)->count());
    }

    public function test_la_ligne_copiee_se_modifie_sans_toucher_au_sous_detail(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleAvecSousDetail();

        $id = $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article, 'quantite' => 1])
            ->assertCreated()->json('id');

        $this->agirEn($this->commercial)
            ->putJson("/api/lignes-devis/{$id}", ['quantite' => 4, 'prix_unitaire_ht' => 110])
            ->assertOk();

        $ligne = $this->chez(fn () => LigneDevis::query()->findOrFail($id));
        $this->assertEqualsWithDelta(110, (float) $ligne->prix_unitaire_ht, 0.001);
        $this->assertEqualsWithDelta(4, (float) $ligne->quantite, 0.001);
        $this->assertEqualsWithDelta(70, (float) $ligne->prix_revient_unitaire, 0.001);
        $this->assertCount(2, $this->chez(fn () => $ligne->composants));
    }

    public function test_l_ajout_est_journalise(): void
    {
        $devis = $this->nouveauDevis();
        $article = $this->articleSimple();

        $this->agirEn($this->commercial)
            ->postJson("/api/devis/{$devis->id}/lignes/depuis-article", ['article_prix_id' => $article->id, 'quantite' => 1])
            ->assertCreated();

        $entrees = Activity::query()
            ->where('subject_id', $devis->id)
            ->where('description', 'like', '%depuis la bibliothèque%')
            ->get();

        $this->assertCount(1, $entrees);
        $this->assertSame('SIMPLE-1', $entrees[0]->properties['reference']);
        $this->assertSame($this->commercial->id, $entrees[0]->causer_id);
    }
}
