<?php

namespace Tests\Feature;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\Parametre;
use App\Models\SeuilTarifaire;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lot 7 de la Bibliotheque de prix : journal des changements de prix (prix
 * force, portee, sous-detail, seuils), historique d'un article, masquage des
 * prix d'achat, cloisonnement par entreprise.
 */
class JournalPrixLot7Test extends TestCase
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

    /**
     * Entrees du journal sur un sujet, de la plus ancienne a la plus recente,
     * `properties` decode. Lecture directe de la table : ni filtre ni modele.
     *
     * @param string $classe Classe du sujet.
     * @param int|null $id Identifiant du sujet, ou null pour tous.
     * @return array<int, object>
     */
    private function entrees(string $classe, ?int $id = null): array
    {
        $requete = DB::table('activity_log')->where('subject_type', (new $classe())->getMorphClass())->orderBy('id');

        if ($id !== null) {
            $requete->where('subject_id', $id);
        }

        return $requete->get()->map(function (object $ligne): object {
            $ligne->proprietes = json_decode((string) $ligne->properties, true) ?? [];

            return $ligne;
        })->all();
    }

    /**
     * @param int $id
     * @return array<int, string> Evenements de l'article, du plus ancien au plus recent.
     */
    private function evenementsArticle(int $id): array
    {
        return array_map(fn (object $e): string => $e->event, $this->entrees(ArticlePrix::class, $id));
    }

    private function article(string $reference = 'ART-J', array $en_plus = []): int
    {
        return $this->agirEn($this->direction)
            ->postJson('/api/bibliotheque-prix', [
                'reference' => $reference, 'designation' => 'Pose de carrelage',
                'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
            ] + $en_plus)
            ->assertCreated()
            ->json('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function fourniture(float $achat = 5): array
    {
        return [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 2,
            'unite' => 'u', 'prix_achat_ht' => $achat, 'prix_vente_ht' => 8,
        ];
    }

    private function seuil(string $type, float $valeur, array $en_plus = []): void
    {
        $this->agirEn($this->direction)
            ->putJson("/api/parametres/seuils/{$type}", ['valeur' => $valeur] + $en_plus)
            ->assertOk();
    }

    #[Test]
    public function la_creation_d_un_article_est_journalisee(): void
    {
        $id = $this->article('ART-C', ['prix_force_ht' => 100]);

        $entrees = $this->entrees(ArticlePrix::class, $id);
        $this->assertCount(1, $entrees);
        $entree = $entrees[0];

        $this->assertSame('article_cree', $entree->event);
        $this->assertStringContainsString('ART-C', $entree->description);
        $this->assertSame($this->direction->id, (int) $entree->causer_id);
        $this->assertSame($this->entreprise->getTenantKey(), $entree->tenant_id);
        $this->assertSame('human', $entree->triggered_by);
        $this->assertNull($entree->automation_rule);
        $this->assertArrayNotHasKey('avant', $entree->proprietes);
        $this->assertSame('entreprise', $entree->proprietes['apres']['portee']);
        $this->assertEqualsWithDelta(100.0, $entree->proprietes['apres']['prix_force_ht'], 0.001);
    }

    #[Test]
    public function le_prix_force_pose_modifie_et_retire_a_chacun_son_entree(): void
    {
        $id = $this->article();

        foreach ([100, 120, null] as $prix) {
            $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => $prix])->assertOk();
        }

        $this->assertSame(
            ['article_cree', 'prix_force_pose', 'prix_force_modifie', 'prix_force_retire'],
            $this->evenementsArticle($id)
        );

        [, $pose, $modifie, $retire] = $this->entrees(ArticlePrix::class, $id);
        $this->assertNull($pose->proprietes['avant']['prix_force_ht']);
        $this->assertEqualsWithDelta(100.0, $pose->proprietes['apres']['prix_force_ht'], 0.001);
        $this->assertEqualsWithDelta(100.0, $modifie->proprietes['avant']['prix_force_ht'], 0.001);
        $this->assertEqualsWithDelta(120.0, $modifie->proprietes['apres']['prix_force_ht'], 0.001);
        $this->assertEqualsWithDelta(120.0, $retire->proprietes['avant']['prix_force_ht'], 0.001);
        $this->assertNull($retire->proprietes['apres']['prix_force_ht']);
        $this->assertStringContainsString('120,00', $retire->description);
    }

    #[Test]
    public function un_enregistrement_sans_changement_n_ajoute_rien(): void
    {
        $id = $this->article('ART-S', ['prix_force_ht' => 100]);

        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 100])->assertOk();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['designation' => 'Pose de carrelage'])->assertOk();

        $this->assertSame(['article_cree'], $this->evenementsArticle($id));
    }

    #[Test]
    public function une_modification_ne_journalise_que_les_champs_qui_changent(): void
    {
        $id = $this->article();

        $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}", ['designation' => 'Pose de faience', 'fournisseur' => 'Point P'])
            ->assertOk();
        $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}", ['designation' => 'Pose de mosaique', 'prix_force_ht' => 80])
            ->assertOk();

        $this->assertSame(
            ['article_cree', 'article_modifie', 'prix_force_pose', 'article_modifie'],
            $this->evenementsArticle($id)
        );

        [, $premiere, , $seconde] = $this->entrees(ArticlePrix::class, $id);
        $this->assertSame(['designation', 'fournisseur'], array_keys($premiere->proprietes['apres']));
        $this->assertSame('Pose de carrelage', $premiere->proprietes['avant']['designation']);
        $this->assertNull($premiere->proprietes['avant']['fournisseur']);
        $this->assertSame('Point P', $premiere->proprietes['apres']['fournisseur']);
        $this->assertSame(['designation'], array_keys($seconde->proprietes['apres']));
        $this->assertSame('Pose de faience', $seconde->proprietes['avant']['designation']);
    }

    #[Test]
    public function la_personnalisation_et_la_publication_sont_journalisees(): void
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

        $copie = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$reference->id}/personnaliser", ['portee' => 'prive'])
            ->assertCreated()
            ->json('id');

        $this->assertSame(['article_personnalise'], $this->evenementsArticle($copie));
        [$entree] = $this->entrees(ArticlePrix::class, $copie);
        $this->assertSame($reference->id, $entree->proprietes['source_id']);
        $this->assertSame('R-0001', $entree->proprietes['source_reference']);
        $this->assertSame(2, $entree->proprietes['composants_copies']);
        $this->assertSame('prive', $entree->proprietes['apres']['portee']);
        $this->assertSame([], $this->entrees(ArticlePrix::class, $reference->id));

        $this->agirEn($this->direction)->postJson("/api/bibliotheque-prix/{$copie}/publier")->assertOk();

        $this->assertSame(['article_personnalise', 'article_publie'], $this->evenementsArticle($copie));
        [, $publie] = $this->entrees(ArticlePrix::class, $copie);
        $this->assertSame('prive', $publie->proprietes['avant']['portee']);
        $this->assertSame($this->direction->id, $publie->proprietes['avant']['proprietaire_id']);
        $this->assertSame('entreprise', $publie->proprietes['apres']['portee']);
        $this->assertNull($publie->proprietes['apres']['proprietaire_id']);
    }

    #[Test]
    public function le_sous_detail_est_journalise_ligne_par_ligne(): void
    {
        $id = $this->article();

        $mo = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", ['type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => 1.5])
            ->assertCreated()->json('composant.id');
        $ligne = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())
            ->assertCreated()->json('composant.id');

        $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}/composants/{$ligne}", ['quantite' => 3, 'prix_achat_ht' => 6])
            ->assertOk();
        // Meme valeur : rien a journaliser.
        $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}/composants/{$ligne}", ['quantite' => 3])
            ->assertOk();
        $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}/composants/ordre", ['ids' => [$ligne, $mo]])
            ->assertOk();
        // Meme ordre : rien a journaliser.
        $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}/composants/ordre", ['ids' => [$ligne, $mo]])
            ->assertOk();
        $this->agirEn($this->direction)->deleteJson("/api/bibliotheque-prix/{$id}/composants/{$mo}")->assertOk();

        $this->assertSame(
            ['article_cree', 'composant_ajoute', 'composant_ajoute', 'composant_modifie', 'composants_reordonnes', 'composant_supprime'],
            $this->evenementsArticle($id)
        );

        [, $ajoutMo, $ajoutLigne, $modifie, $reordonne, $supprime] = $this->entrees(ArticlePrix::class, $id);

        $this->assertSame($mo, $ajoutMo->proprietes['composant_id']);
        $this->assertSame('main_oeuvre', $ajoutMo->proprietes['apres']['type']);
        $this->assertSame('ouvrier', $ajoutMo->proprietes['apres']['qualification']);
        $this->assertEqualsWithDelta(1.5, $ajoutMo->proprietes['apres']['quantite'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $ajoutLigne->proprietes['apres']['prix_achat_ht'], 0.0001);

        $this->assertSame($ligne, $modifie->proprietes['composant_id']);
        $this->assertEqualsWithDelta(2.0, $modifie->proprietes['avant']['quantite'], 0.0001);
        $this->assertEqualsWithDelta(3.0, $modifie->proprietes['apres']['quantite'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $modifie->proprietes['avant']['prix_achat_ht'], 0.0001);
        $this->assertEqualsWithDelta(6.0, $modifie->proprietes['apres']['prix_achat_ht'], 0.0001);
        $this->assertArrayNotHasKey('designation', $modifie->proprietes['apres']);

        $this->assertSame([$mo, $ligne], $reordonne->proprietes['avant']['ordre_des_lignes']);
        $this->assertSame([$ligne, $mo], $reordonne->proprietes['apres']['ordre_des_lignes']);

        $this->assertSame($mo, $supprime->proprietes['composant_id']);
        $this->assertSame('main_oeuvre', $supprime->proprietes['avant']['type']);
        $this->assertArrayNotHasKey('apres', $supprime->proprietes);
    }

    #[Test]
    public function l_historique_se_lit_du_plus_recent_au_plus_ancien_avec_l_auteur(): void
    {
        $id = $this->article();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 100])->assertOk();
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 110])->assertOk();

        $reponse = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$id}/historique")->assertOk();

        $this->assertSame(3, $reponse->json('total'));
        $this->assertSame(
            ['prix_force_modifie', 'prix_force_pose', 'article_cree'],
            collect($reponse->json('data'))->pluck('evenement')->all()
        );
        $premiere = $reponse->json('data.0');
        $this->assertSame($this->direction->id, $premiere['auteur']['id']);
        $this->assertSame('Test Direction', $premiere['auteur']['nom']);
        $this->assertSame('human', $premiere['declenche_par']);
        $this->assertNull($premiere['regle_automatisation']);
        $this->assertEqualsWithDelta(100.0, $premiere['avant']['prix_force_ht'], 0.001);
        $this->assertEqualsWithDelta(110.0, $premiere['apres']['prix_force_ht'], 0.001);
        $this->assertFalse($premiere['details_masques']);
        $this->assertNotNull($premiere['date']);

        $page = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$id}/historique?par_page=2")->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(3, $page->json('total'));
    }

    #[Test]
    public function les_prix_d_achat_sont_masques_dans_l_historique_sans_la_permission(): void
    {
        $id = $this->article();
        $ligne = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())
            ->assertCreated()->json('composant.id');
        $this->agirEn($this->direction)
            ->putJson("/api/bibliotheque-prix/{$id}/composants/{$ligne}", ['prix_achat_ht' => 6])
            ->assertOk();

        $lecteur = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $lecteur->givePermissionTo('bibliotheque.consulter');

        foreach ([$this->direction, $this->creerUtilisateur($this->entreprise, 'Commercial')] as $autorise) {
            $reponse = $this->agirEn($autorise)->getJson("/api/bibliotheque-prix/{$id}/historique")->assertOk();
            $this->assertEqualsWithDelta(5.0, $reponse->json('data.1.apres.prix_achat_ht'), 0.001);
            $this->assertEqualsWithDelta(6.0, $reponse->json('data.0.apres.prix_achat_ht'), 0.001);
            $this->assertFalse($reponse->json('data.0.details_masques'));
        }

        $reponse = $this->agirEn($lecteur)->getJson("/api/bibliotheque-prix/{$id}/historique")->assertOk();
        // Modification d'un prix d'achat seul : l'entree reste, ses valeurs disparaissent.
        $reponse->assertJsonMissingPath('data.0.apres.prix_achat_ht');
        $reponse->assertJsonMissingPath('data.0.avant.prix_achat_ht');
        $this->assertTrue($reponse->json('data.0.details_masques'));
        // Ajout de la ligne : le reste de l'entree est lisible.
        $reponse->assertJsonMissingPath('data.1.apres.prix_achat_ht');
        $this->assertSame('Colle', $reponse->json('data.1.apres.designation'));
        $this->assertEqualsWithDelta(8.0, $reponse->json('data.1.apres.prix_vente_ht'), 0.001);
        $this->assertTrue($reponse->json('data.1.details_masques'));
        // Les prix d'achat restent ecrits dans le journal : seul l'affichage est masque.
        $entrees = $this->entrees(ArticlePrix::class, $id);
        $this->assertEqualsWithDelta(5.0, $entrees[1]->proprietes['apres']['prix_achat_ht'], 0.001);
    }

    #[Test]
    public function l_historique_suit_la_visibilite_de_l_article(): void
    {
        $this->dansEntreprise($this->entreprise, fn () => Parametre::ecrire('commercial_peut_modifier_bibliotheque', true));
        $proprietaire = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $id = $this->agirEn($proprietaire)
            ->postJson('/api/bibliotheque-prix', [
                'reference' => 'PRIV-1', 'designation' => 'Version privee',
                'type_article' => 'materiaux', 'unite' => 'u', 'taux_tva' => 20,
            ])
            ->assertCreated()
            ->assertJsonPath('portee', 'prive')
            ->json('id');

        $this->agirEn($proprietaire)->getJson("/api/bibliotheque-prix/{$id}/historique")
            ->assertOk()
            ->assertJsonPath('total', 1);
        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Commercial'))
            ->getJson("/api/bibliotheque-prix/{$id}/historique")
            ->assertNotFound();

        $autre = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');
        $publique = $this->article('ART-PUB');
        $this->agirEn($autre)->getJson("/api/bibliotheque-prix/{$publique}/historique")->assertNotFound();
    }

    #[Test]
    public function sans_la_permission_consulter_l_historique_est_refuse(): void
    {
        $id = $this->article();

        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Salarié terrain'))
            ->getJson("/api/bibliotheque-prix/{$id}/historique")
            ->assertForbidden();
    }

    #[Test]
    public function les_seuils_fixes_et_modifies_sont_journalises(): void
    {
        $this->seuil('marge_mo_min', 20);
        $this->seuil('marge_mo_min', 20);
        $this->seuil('marge_mo_min', 25);
        $this->seuil('marge_mo_min', 25, ['actif' => false]);

        $evenements = array_map(fn (object $e): string => $e->event, $this->entrees(SeuilTarifaire::class));
        $this->assertSame(['seuil_fixe', 'seuil_modifie', 'seuil_modifie'], $evenements);

        [$fixe, $change, $desactive] = $this->entrees(SeuilTarifaire::class);
        $this->assertSame('marge_mo_min', $fixe->proprietes['type']);
        $this->assertEqualsWithDelta(20.0, $fixe->proprietes['apres']['valeur'], 0.001);
        $this->assertEqualsWithDelta(20.0, $change->proprietes['avant']['valeur'], 0.001);
        $this->assertEqualsWithDelta(25.0, $change->proprietes['apres']['valeur'], 0.001);
        $this->assertArrayNotHasKey('actif', $change->proprietes['apres']);
        $this->assertTrue($desactive->proprietes['avant']['actif']);
        $this->assertFalse($desactive->proprietes['apres']['actif']);
        $this->assertSame($this->direction->id, (int) $fixe->causer_id);

        $reponse = $this->agirEn($this->direction)->getJson('/api/parametres/seuils/historique')->assertOk();
        $this->assertSame(3, $reponse->json('total'));
        $this->assertSame('seuil_modifie', $reponse->json('data.0.evenement'));
        $this->assertSame('seuil_fixe', $reponse->json('data.2.evenement'));

        foreach (['Administratif', 'Commercial', 'Conducteur travaux'] as $role) {
            $this->agirEn($this->creerUtilisateur($this->entreprise, $role))
                ->getJson('/api/parametres/seuils/historique')
                ->assertForbidden();
        }
    }

    #[Test]
    public function le_journal_d_une_entreprise_reste_chez_elle(): void
    {
        $this->seuil('marge_mo_min', 20);

        $autre = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');
        $this->agirEn($autre)->putJson('/api/parametres/seuils/marge_mo_min', ['valeur' => 30])->assertOk();
        $this->agirEn($autre)->putJson('/api/parametres/seuils/marge_fournitures_min', ['valeur' => 10])->assertOk();

        $this->assertSame(1, $this->agirEn($this->direction)->getJson('/api/parametres/seuils/historique')->json('total'));
        $reponse = $this->agirEn($autre)->getJson('/api/parametres/seuils/historique')->assertOk();
        $this->assertSame(2, $reponse->json('total'));
        $this->assertEqualsWithDelta(30.0, $reponse->json('data.1.apres.valeur'), 0.001);
        $this->assertSame(3, DB::table('activity_log')->where('event', 'like', 'seuil_%')->count());
    }

    #[Test]
    public function une_ecriture_refusee_n_ajoute_rien_au_journal(): void
    {
        $id = $this->article();
        $this->seuil('marge_mo_min', 20);
        $avant = DB::table('activity_log')->count();

        // Pas de sous-detail : le revient est inconnu, le prix force du Conducteur est refuse.
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $this->agirEn($conducteur)->putJson("/api/bibliotheque-prix/{$id}", ['prix_force_ht' => 50])->assertUnprocessable();

        // Droit insuffisant sur un article d'entreprise.
        $this->dansEntreprise($this->entreprise, fn () => Parametre::ecrire('commercial_peut_modifier_bibliotheque', true));
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $this->agirEn($commercial)->putJson("/api/bibliotheque-prix/{$id}", ['designation' => 'Autre'])->assertForbidden();
        $this->agirEn($commercial)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->fourniture())
            ->assertForbidden();

        $this->assertSame($avant, DB::table('activity_log')->count());
        $this->assertSame(['article_cree'], $this->evenementsArticle($id));
    }

    #[Test]
    public function le_journal_reste_en_ecriture_seule(): void
    {
        $id = $this->article();
        $entree = $this->entrees(ArticlePrix::class, $id)[0];

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('activity_log')->where('id', $entree->id)->update(['description' => 'modifiee']);
    }
}
