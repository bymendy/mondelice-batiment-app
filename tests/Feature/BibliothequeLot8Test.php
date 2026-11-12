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
 * Lot 8 de la Bibliotheque de prix : prix de vente calcule depuis le prix
 * d'achat (marge par defaut), prix pose seule, descriptif limite, enregistrement
 * complet du sous-detail, arrondi ligne par ligne.
 */
class BibliothequeLot8Test extends TestCase
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

    private function article(string $reference = 'ART-8', array $en_plus = []): int
    {
        return $this->agirEn($this->direction)
            ->postJson('/api/bibliotheque-prix', [
                'reference' => $reference, 'designation' => 'Pose de carrelage',
                'type_article' => 'main_oeuvre', 'unite' => 'm2', 'taux_tva' => 10,
            ] + $en_plus)
            ->assertCreated()
            ->json('id');
    }

    private function marge(?float $valeur, ?User $qui = null): \Illuminate\Testing\TestResponse
    {
        return $this->agirEn($qui ?? $this->direction)->putJson('/api/parametres/marge_fournitures_defaut', ['valeur' => $valeur]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(array $en_plus = []): array
    {
        return $en_plus + [
            'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 1, 'unite' => 'u',
        ];
    }

    private function mo(float $heures = 1, array $en_plus = []): array
    {
        return $en_plus + ['type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'quantite' => $heures];
    }

    private function entreesBatch(int $id): array
    {
        return DB::table('activity_log')
            ->where('subject_type', (new ArticlePrix())->getMorphClass())
            ->where('subject_id', $id)
            ->where('event', 'sous_detail_modifie')
            ->orderBy('id')
            ->get()
            ->map(function (object $e): object {
                $e->proprietes = json_decode((string) $e->properties, true) ?? [];

                return $e;
            })->all();
    }

    #[Test]
    public function le_prix_de_vente_se_calcule_depuis_le_prix_d_achat_avec_la_marge_par_defaut(): void
    {
        $this->marge(50)->assertOk();
        $id = $this->article();

        // Marge de 50 % du prix de vente : 5 / (1 - 0,5) = 10.
        $reponse = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->ligne(['prix_achat_ht' => 5]))
            ->assertCreated();

        $this->assertEqualsWithDelta(10.0, (float) $reponse->json('composant.prix_vente_ht'), 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $reponse->json('article.prix_vente_ht'), 0.001);
    }

    #[Test]
    public function un_prix_de_vente_saisi_est_respecte(): void
    {
        $this->marge(50)->assertOk();
        $id = $this->article();

        $reponse = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->ligne(['prix_achat_ht' => 5, 'prix_vente_ht' => 7]))
            ->assertCreated();

        $this->assertEqualsWithDelta(7.0, (float) $reponse->json('composant.prix_vente_ht'), 0.0001);
    }

    #[Test]
    public function sans_prix_de_vente_ni_marge_ni_prix_d_achat_la_ligne_est_refusee(): void
    {
        $id = $this->article();

        // Pas de marge par defaut reglee.
        $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->ligne(['prix_achat_ht' => 5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prix_vente_ht');

        // Marge reglee, mais pas de prix d'achat.
        $this->marge(30)->assertOk();
        $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$id}/composants", $this->ligne())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prix_vente_ht');

        $this->assertSame(0, DB::table('article_composants')->count());
    }

    #[Test]
    public function l_administratif_ne_peut_pas_regler_la_marge_sous_le_seuil_actif_mais_la_direction_le_peut(): void
    {
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');

        $this->agirEn($this->direction)
            ->putJson('/api/parametres/seuils/marge_fournitures_min', ['valeur' => 20])
            ->assertOk();

        $this->marge(10, $administratif)->assertUnprocessable()->assertJsonValidationErrors('valeur');
        $this->marge(25, $administratif)->assertOk();
        $this->marge(10, $this->direction)->assertOk();
        $this->marge(null, $this->direction)->assertOk();

        $this->assertNull(Parametre::lire('marge_fournitures_defaut'));
    }

    #[Test]
    public function la_liste_des_reglages_contient_la_marge_par_defaut(): void
    {
        $cles = collect($this->agirEn($this->direction)->getJson('/api/parametres')->assertOk()->json())->pluck('cle');

        $this->assertTrue($cles->contains('marge_fournitures_defaut'));
    }

    #[Test]
    public function le_prix_pose_seule_exclut_les_fournitures_et_son_revient_est_masque(): void
    {
        $id = $this->article();
        $composants = "/api/bibliotheque-prix/{$id}/composants";

        $this->agirEn($this->direction)->postJson($composants, $this->mo(2))->assertCreated();                      // 120 (revient 80)
        $this->agirEn($this->direction)->postJson($composants, $this->ligne([
            'type' => 'materiel', 'designation' => 'Echafaudage', 'quantite' => 1, 'prix_achat_ht' => 8, 'prix_vente_ht' => 12,
        ]))->assertCreated();                                                                                       // 12 (revient 8)
        $this->agirEn($this->direction)->postJson($composants, $this->ligne([
            'quantite' => 3, 'prix_achat_ht' => 10, 'prix_vente_ht' => 20,
        ]))->assertCreated();                                                                                       // fourniture : exclue

        $article = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$id}")->assertOk();
        $this->assertEqualsWithDelta(132.0, (float) $article->json('prix_pose_seule_ht'), 0.001);
        $this->assertEqualsWithDelta(88.0, (float) $article->json('prix_revient_pose_seule_ht'), 0.001);
        $this->assertEqualsWithDelta(192.0, (float) $article->json('prix_vente_ht'), 0.001);

        $lecteur = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $lecteur->givePermissionTo('bibliotheque.consulter');

        $vu = $this->agirEn($lecteur)->getJson("/api/bibliotheque-prix/{$id}")->assertOk();
        $this->assertEqualsWithDelta(132.0, (float) $vu->json('prix_pose_seule_ht'), 0.001);
        $vu->assertJsonMissingPath('prix_revient_pose_seule_ht');
    }

    #[Test]
    public function le_descriptif_est_limite_a_2400_caracteres_sans_compter_la_mise_en_forme(): void
    {
        $dans = '<p><strong>' . str_repeat('a', 2400) . '</strong></p>';
        $trop = '<p><strong>' . str_repeat('a', 2401) . '</strong></p>';

        $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'D-OK', 'designation' => 'x', 'type_article' => 'main_oeuvre', 'unite' => 'u',
            'taux_tva' => 10, 'description' => $dans,
        ])->assertCreated();

        $this->agirEn($this->direction)->postJson('/api/bibliotheque-prix', [
            'reference' => 'D-KO', 'designation' => 'x', 'type_article' => 'main_oeuvre', 'unite' => 'u',
            'taux_tva' => 10, 'description' => $trop,
        ])->assertUnprocessable()->assertJsonValidationErrors('description');

        $id = $this->article('D-UP');
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['description' => $trop])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->agirEn($this->direction)->putJson("/api/bibliotheque-prix/{$id}", ['description' => $dans])->assertOk();
    }

    #[Test]
    public function l_enregistrement_complet_met_a_jour_cree_supprime_et_reordonne(): void
    {
        $this->marge(50)->assertOk();
        $id = $this->article();
        $url = "/api/bibliotheque-prix/{$id}/composants";

        $a = $this->agirEn($this->direction)->postJson($url, $this->ligne(['designation' => 'A', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]))->json('composant.id');
        $b = $this->agirEn($this->direction)->postJson($url, $this->ligne(['designation' => 'B', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]))->json('composant.id');
        $c = $this->agirEn($this->direction)->postJson($url, $this->ligne(['designation' => 'C', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]))->json('composant.id');

        // B d'abord (modifie), une nouvelle fourniture (prix de vente calcule), une main d'oeuvre neuve ; A reste, C disparait.
        $reponse = $this->agirEn($this->direction)->putJson($url, ['composants' => [
            $this->ligne(['id' => $b, 'designation' => 'B2', 'quantite' => 2, 'prix_achat_ht' => 2, 'prix_vente_ht' => 5]),
            $this->ligne(['designation' => 'Neuve', 'quantite' => 3, 'prix_achat_ht' => 5]),
            $this->mo(1),
            $this->ligne(['id' => $a, 'designation' => 'A', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]),
        ]])->assertOk();

        $lignes = collect($reponse->json('data'));
        $this->assertCount(4, $lignes);
        $this->assertSame(['B2', 'Neuve'], $lignes->take(2)->pluck('designation')->all());
        $this->assertSame($b, $lignes[0]['id']);
        $this->assertSame($a, $lignes[3]['id']);
        $this->assertNotContains($c, $lignes->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4], $lignes->pluck('ordre')->all());

        // 2 x 5 + 3 x 10 + 1 h x 60 + 1 x 4 = 104
        $this->assertEqualsWithDelta(104.0, (float) $reponse->json('article.prix_vente_ht'), 0.001);
        $this->assertSame(0, DB::table('article_composants')->where('id', $c)->count());

        $entrees = $this->entreesBatch($id);
        $this->assertCount(1, $entrees);
        $this->assertSame(2, $entrees[0]->proprietes['ajoutees']);
        $this->assertSame(2, $entrees[0]->proprietes['modifiees']); // B (modifiee) et A (changee de position)
        $this->assertSame(1, $entrees[0]->proprietes['supprimees']);
    }

    #[Test]
    public function une_ligne_invalide_annule_tout_l_enregistrement(): void
    {
        $id = $this->article();
        $url = "/api/bibliotheque-prix/{$id}/composants";
        $a = $this->agirEn($this->direction)->postJson($url, $this->ligne(['designation' => 'A', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]))->json('composant.id');

        $this->agirEn($this->direction)->putJson($url, ['composants' => [
            $this->ligne(['id' => $a, 'designation' => 'A modifiee', 'prix_vente_ht' => 9]),
            $this->ligne(['designation' => 'Sans prix']), // ni vente, ni achat, pas de marge
        ]])->assertUnprocessable();

        $this->agirEn($this->direction)->putJson($url, ['composants' => [
            $this->ligne(['id' => $a, 'designation' => 'A modifiee', 'prix_vente_ht' => 9]),
            $this->ligne(['designation' => '', 'prix_vente_ht' => 1]),
        ]])->assertUnprocessable()->assertJsonValidationErrors('composants.1.designation');

        $this->assertSame('A', DB::table('article_composants')->where('id', $a)->value('designation'));
        $this->assertSame([], $this->entreesBatch($id));
    }

    #[Test]
    public function le_type_d_une_ligne_existante_ne_change_pas_et_un_id_etranger_est_refuse(): void
    {
        $id = $this->article();
        $autre = $this->article('ART-AUTRE');
        $url = "/api/bibliotheque-prix/{$id}/composants";

        $a = $this->agirEn($this->direction)->postJson($url, $this->ligne(['prix_achat_ht' => 2, 'prix_vente_ht' => 4]))->json('composant.id');
        $etrangere = $this->agirEn($this->direction)
            ->postJson("/api/bibliotheque-prix/{$autre}/composants", $this->ligne(['prix_achat_ht' => 2, 'prix_vente_ht' => 4]))
            ->json('composant.id');

        $this->agirEn($this->direction)->putJson($url, ['composants' => [
            ['id' => $a, 'type' => 'materiel', 'designation' => 'Colle', 'quantite' => 1, 'unite' => 'u', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4],
        ]])->assertUnprocessable();

        $this->agirEn($this->direction)->putJson($url, ['composants' => [
            $this->ligne(['id' => $etrangere, 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]),
        ]])->assertUnprocessable();

        $this->assertSame(1, DB::table('article_composants')->where('article_id', $autre)->count());
    }

    #[Test]
    public function le_commercial_ne_peut_pas_remplacer_le_sous_detail_d_un_article_de_l_entreprise(): void
    {
        $id = $this->article();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->putJson("/api/bibliotheque-prix/{$id}/composants", ['composants' => [$this->mo(1)]])
            ->assertForbidden();
    }

    #[Test]
    public function rien_n_est_journalise_quand_le_sous_detail_ne_change_pas(): void
    {
        $id = $this->article();
        $url = "/api/bibliotheque-prix/{$id}/composants";
        $a = $this->agirEn($this->direction)->postJson($url, $this->ligne(['designation' => 'A', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]))->json('composant.id');

        $this->agirEn($this->direction)->putJson($url, ['composants' => [
            $this->ligne(['id' => $a, 'designation' => 'A', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]),
        ]])->assertOk();

        $this->assertSame([], $this->entreesBatch($id));
    }

    #[Test]
    public function l_historique_masque_les_prix_d_achat_des_lignes_enregistrees_d_un_coup(): void
    {
        $id = $this->article();
        $url = "/api/bibliotheque-prix/{$id}/composants";

        $this->agirEn($this->direction)->putJson($url, ['composants' => [
            $this->ligne(['designation' => 'A', 'prix_achat_ht' => 2, 'prix_vente_ht' => 4]),
        ]])->assertOk();

        // Le journal garde le prix d'achat en base...
        $this->assertStringContainsString('prix_achat_ht', (string) $this->entreesBatch($id)[0]->properties);

        // ... mais l'historique l'enleve, a tous les niveaux, sans la permission.
        $lecteur = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $lecteur->givePermissionTo('bibliotheque.consulter');

        $vu = $this->agirEn($lecteur)->getJson("/api/bibliotheque-prix/{$id}/historique")->assertOk();
        $this->assertStringNotContainsString('prix_achat_ht', (string) $vu->getContent());
        $vu->assertJsonPath('data.0.evenement', 'sous_detail_modifie');
        $vu->assertJsonPath('data.0.details_masques', true);
        $vu->assertJsonPath('data.0.apres.lignes.0.designation', 'A');

        $complet = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$id}/historique")->assertOk();
        $this->assertEqualsWithDelta(2.0, (float) $complet->json('data.0.apres.lignes.0.prix_achat_ht'), 0.001);
    }

    #[Test]
    public function l_api_arrondit_chaque_ligne_au_centime(): void
    {
        $id = $this->article();
        $url = "/api/bibliotheque-prix/{$id}/composants";

        foreach (range(1, 3) as $i) {
            $this->agirEn($this->direction)->postJson($url, $this->ligne([
                'designation' => "L{$i}", 'prix_achat_ht' => 0.5, 'prix_vente_ht' => 1.004,
            ]))->assertCreated();
        }

        $article = $this->agirEn($this->direction)->getJson("/api/bibliotheque-prix/{$id}")->assertOk();
        $this->assertEqualsWithDelta(3.00, (float) $article->json('prix_vente_ht'), 0.0001);
    }
}
