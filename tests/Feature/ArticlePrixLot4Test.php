<?php

namespace Tests\Feature;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\CategoriePrix;
use App\Models\Parametre;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lot 4 de la Bibliotheque de prix : versions privees et d'entreprise,
 * permission `bibliotheque.publier_entreprise`, personnalisation d'une
 * reference, version affichee (privee > entreprise > reference), versions
 * orphelines et codes automatiques.
 */
class ArticlePrixLot4Test extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seederRolesEtPermissions();
        $this->entreprise = $this->creerEntreprise();
    }

    /**
     * @param array<string, mixed> $surcharge
     * @return array<string, mixed>
     */
    private function donnees(array $surcharge = []): array
    {
        return array_merge([
            'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre',
            'unite' => 'm2',
            'taux_tva' => 10,
        ], $surcharge);
    }

    private function activerPourLeCommercial(): void
    {
        $this->dansEntreprise(
            $this->entreprise,
            fn () => Parametre::ecrire('commercial_peut_modifier_bibliotheque', true)
        );
    }

    /**
     * Article de reference avec deux lignes de sous-detail.
     *
     * @param string $code
     * @param int|null $categorieId
     * @return ArticlePrix
     */
    private function reference(string $code, ?int $categorieId = null): ArticlePrix
    {
        $reference = ArticlePrix::creerReference([
            'reference' => $code, 'designation' => 'Reference ' . $code,
            'type_article' => 'materiaux', 'unite' => 'u', 'taux_tva' => 20,
            'categorie_id' => $categorieId, 'source' => 'mondelice',
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

    /**
     * @param \Illuminate\Testing\TestResponse $reponse
     * @return array<int, string>
     */
    private function codes($reponse): array
    {
        return collect($reponse->json('data'))->pluck('reference')->sort()->values()->all();
    }

    #[Test]
    public function le_commercial_cree_une_version_privee_que_personne_d_autre_ne_voit(): void
    {
        $this->activerPourLeCommercial();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $id = $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'PRIV-1']))
            ->assertCreated()
            ->assertJsonPath('portee', 'prive')
            ->assertJsonPath('proprietaire_id', $commercial->id)
            ->json('id');

        $this->assertSame(['PRIV-1'], $this->codes($this->agirEn($commercial)->getJson('/api/bibliotheque-prix')->assertOk()));
        $this->assertSame([], $this->codes($this->agirEn($collegue)->getJson('/api/bibliotheque-prix')->assertOk()));
        $this->assertSame([], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix')->assertOk()));

        $this->agirEn($collegue)->getJson('/api/bibliotheque-prix/' . $id)->assertNotFound();
        $this->agirEn($direction)->getJson('/api/bibliotheque-prix/' . $id)->assertNotFound();
        $this->agirEn($direction)->putJson('/api/bibliotheque-prix/' . $id, ['designation' => 'Piratage'])->assertNotFound();
        $this->agirEn($direction)->deleteJson('/api/bibliotheque-prix/' . $id)->assertNotFound();

        // Le proprietaire modifie sa version, mais ne change pas sa portee par la modification.
        $this->agirEn($commercial)
            ->putJson('/api/bibliotheque-prix/' . $id, ['designation' => 'Ma version', 'portee' => 'entreprise'])
            ->assertOk()
            ->assertJsonPath('designation', 'Ma version')
            ->assertJsonPath('portee', 'prive');
    }

    #[Test]
    public function le_commercial_ne_cree_pas_d_article_pour_toute_l_entreprise(): void
    {
        $this->activerPourLeCommercial();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'ENT-NON', 'portee' => 'entreprise']))
            ->assertForbidden();

        $this->assertDatabaseMissing('articles_prix', ['reference' => 'ENT-NON']);
    }

    #[Test]
    public function direction_et_conducteur_creent_par_defaut_un_article_d_entreprise(): void
    {
        foreach (['Direction', 'Conducteur travaux'] as $role) {
            $this->agirEn($this->creerUtilisateur($this->entreprise, $role))
                ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'E-' . substr($role, 0, 3)]))
                ->assertCreated()
                ->assertJsonPath('portee', 'entreprise')
                ->assertJsonPath('proprietaire_id', null);
        }
    }

    #[Test]
    public function modifier_un_article_de_l_entreprise_demande_de_pouvoir_publier(): void
    {
        $this->activerPourLeCommercial();
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $id = $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'ENT-1']))
            ->json('id');

        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Commercial'))
            ->putJson('/api/bibliotheque-prix/' . $id, ['designation' => 'Non'])
            ->assertForbidden();
        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Administratif'))
            ->putJson('/api/bibliotheque-prix/' . $id, ['designation' => 'Non'])
            ->assertForbidden();

        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Conducteur travaux'))
            ->putJson('/api/bibliotheque-prix/' . $id, ['designation' => 'Oui conducteur'])
            ->assertOk();
        $this->agirEn($direction)
            ->putJson('/api/bibliotheque-prix/' . $id, ['designation' => 'Oui direction'])
            ->assertOk()
            ->assertJsonPath('designation', 'Oui direction');
    }

    #[Test]
    public function personnaliser_une_reference_copie_l_article_et_son_sous_detail_avec_un_code_automatique(): void
    {
        $this->activerPourLeCommercial();
        $categorie = CategoriePrix::creerReference(['niveau' => 2, 'ordre' => 4, 'code' => '1.4', 'nom' => 'Carrelage']);
        $premiere = $this->reference('R-0001', $categorie->id);
        $seconde = $this->reference('R-0002', $categorie->id);
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $reponse = $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix/' . $premiere->id . '/personnaliser')
            ->assertCreated()
            ->assertJsonPath('reference', '1.4-0001')
            ->assertJsonPath('portee', 'prive')
            ->assertJsonPath('proprietaire_id', $commercial->id)
            ->assertJsonPath('article_origine_id', $premiere->id)
            ->assertJsonPath('categorie_id', $categorie->id)
            ->assertJsonPath('est_reference', false);

        $this->assertSame(2, DB::table('article_composants')->where('article_id', $reponse->json('id'))->count());
        $this->assertSame(2, DB::table('article_composants')->where('article_id', $premiere->id)->count());

        $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix/' . $seconde->id . '/personnaliser')
            ->assertCreated()
            ->assertJsonPath('reference', '1.4-0002');

        // Une seule version privee par article d'origine et par proprietaire.
        $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix/' . $premiere->id . '/personnaliser')
            ->assertUnprocessable();

        // La reference n'a pas bouge.
        $this->assertDatabaseHas('articles_prix', ['id' => $premiere->id, 'reference' => 'R-0001', 'tenant_id' => null]);
    }

    #[Test]
    public function la_liste_n_affiche_qu_une_version_par_article_la_plus_proche_de_l_utilisateur(): void
    {
        $this->activerPourLeCommercial();
        $r1 = $this->reference('R-0001');
        $this->reference('R-0002');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $administratif = $this->creerUtilisateur($this->entreprise, 'Administratif');

        // Avant toute personnalisation : les deux references.
        $this->assertSame(['R-0001', 'R-0002'], $this->codes($this->agirEn($commercial)->getJson('/api/bibliotheque-prix')));

        // La Direction personnalise R-0001 pour l'entreprise : code SC-0001.
        $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser')
            ->assertCreated()
            ->assertJsonPath('portee', 'entreprise')
            ->assertJsonPath('reference', 'SC-0001');

        $this->assertSame(['R-0002', 'SC-0001'], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix')));
        $this->assertSame(['R-0002', 'SC-0001'], $this->codes($this->agirEn($administratif)->getJson('/api/bibliotheque-prix')));

        // Le Commercial personnalise a son tour : sa version privee passe devant les deux autres.
        $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser')
            ->assertCreated()
            ->assertJsonPath('portee', 'prive')
            ->assertJsonPath('reference', 'SC-0002');

        $this->assertSame(['R-0002', 'SC-0002'], $this->codes($this->agirEn($commercial)->getJson('/api/bibliotheque-prix')));
        // Les autres ne voient toujours que la version de l'entreprise.
        $this->assertSame(['R-0002', 'SC-0001'], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix')));
        $this->assertSame(['R-0002', 'SC-0001'], $this->codes($this->agirEn($administratif)->getJson('/api/bibliotheque-prix')));

        // Filtre par portee.
        $this->assertSame(['SC-0002'], $this->codes($this->agirEn($commercial)->getJson('/api/bibliotheque-prix?portee=prive')));
        $this->assertSame([], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix?portee=prive')));

        // Archiver la version d'entreprise fait reapparaitre la reference pour ceux qui n'ont pas de version privee.
        $idEntreprise = ArticlePrix::sansFiltreTenant()->where('reference', 'SC-0001')->value('id');
        $this->agirEn($direction)->deleteJson('/api/bibliotheque-prix/' . $idEntreprise)->assertNoContent();
        $this->assertSame(['R-0001', 'R-0002'], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix')));
    }

    #[Test]
    public function on_ne_personnalise_pas_son_propre_article(): void
    {
        $this->activerPourLeCommercial();
        $r1 = $this->reference('R-0001');
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $idPrive = $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser')
            ->assertCreated()
            ->json('id');
        $this->agirEn($commercial)->postJson('/api/bibliotheque-prix/' . $idPrive . '/personnaliser')->assertUnprocessable();

        // Article cree de toutes pieces : aucune reference a personnaliser.
        $idMaison = $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'MAISON-1']))
            ->json('id');
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix/' . $idMaison . '/personnaliser')->assertUnprocessable();

        // La version de l'entreprise existe : la Direction ne peut pas en creer une deuxieme.
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser')->assertCreated();
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser')->assertUnprocessable();
    }

    #[Test]
    public function le_commercial_dont_le_reglage_est_desactive_ne_personnalise_pas(): void
    {
        $r1 = $this->reference('R-0001');
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');

        $this->agirEn($commercial)->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser')->assertForbidden();
        $this->agirEn($commercial)->getJson('/api/bibliotheque-prix/' . $r1->id)->assertOk();
    }

    #[Test]
    public function l_administratif_ne_personnalise_pas(): void
    {
        $r1 = $this->reference('R-0001');

        $this->agirEn($this->creerUtilisateur($this->entreprise, 'Administratif'))
            ->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser')
            ->assertForbidden();
    }

    #[Test]
    public function le_conducteur_publie_une_version_privee_pour_l_entreprise_pas_le_commercial(): void
    {
        $this->activerPourLeCommercial();
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $idConducteur = $this->agirEn($conducteur)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'CD-1', 'portee' => 'prive']))
            ->assertCreated()
            ->assertJsonPath('portee', 'prive')
            ->json('id');
        $this->assertSame([], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix')));

        $this->agirEn($conducteur)
            ->postJson('/api/bibliotheque-prix/' . $idConducteur . '/publier')
            ->assertOk()
            ->assertJsonPath('portee', 'entreprise')
            ->assertJsonPath('proprietaire_id', null);
        $this->assertSame(['CD-1'], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix')));
        $this->assertNotNull(ArticlePrix::sansFiltreTenant()->find($idConducteur)->publie_le);

        // Deja publiee : rien a publier.
        $this->agirEn($conducteur)->postJson('/api/bibliotheque-prix/' . $idConducteur . '/publier')->assertUnprocessable();

        $idCommercial = $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'CO-1']))
            ->json('id');
        $this->agirEn($commercial)->postJson('/api/bibliotheque-prix/' . $idCommercial . '/publier')->assertForbidden();
        // Et la Direction ne peut pas publier la version privee d'un autre : elle ne la voit pas.
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix/' . $idCommercial . '/publier')->assertNotFound();
    }

    #[Test]
    public function on_ne_publie_pas_une_deuxieme_version_d_entreprise_du_meme_article(): void
    {
        $r1 = $this->reference('R-0001');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $conducteur = $this->creerUtilisateur($this->entreprise, 'Conducteur travaux');

        $this->agirEn($direction)->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser')->assertCreated();
        $idPrive = $this->agirEn($conducteur)
            ->postJson('/api/bibliotheque-prix/' . $r1->id . '/personnaliser', ['portee' => 'prive'])
            ->assertCreated()
            ->json('id');

        $this->agirEn($conducteur)->postJson('/api/bibliotheque-prix/' . $idPrive . '/publier')->assertUnprocessable();
    }

    #[Test]
    public function la_base_refuse_deux_versions_d_entreprise_du_meme_article(): void
    {
        $r1 = $this->reference('R-0001');
        $this->dansEntreprise($this->entreprise, function () use ($r1): void {
            $copie = fn (string $code) => ArticlePrix::create([
                'reference' => $code, 'designation' => 'Version', 'type_article' => 'materiaux',
                'unite' => 'u', 'taux_tva' => 20,
            ]);
            $a = $copie('V-1');
            $a->forceFill(['article_origine_id' => $r1->id])->save();

            $this->expectException(QueryException::class);
            $b = $copie('V-2');
            $b->forceFill(['article_origine_id' => $r1->id])->save();
        });
    }

    #[Test]
    public function une_version_privee_orpheline_n_est_visible_que_de_la_direction(): void
    {
        $this->activerPourLeCommercial();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $collegue = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $id = $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'ORPH-1']))
            ->json('id');

        // Suppression du proprietaire : la cle etrangere remet proprietaire_id a NULL.
        // La base doit l'accepter (la contrainte « prive => proprietaire » a ete retiree).
        DB::table('articles_prix')->where('id', $id)->update(['proprietaire_id' => null]);

        // Liste normale : invisible pour tous, Direction comprise.
        $this->assertSame([], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix')));
        $this->assertSame([], $this->codes($this->agirEn($commercial)->getJson('/api/bibliotheque-prix')));

        // Liste des orphelines : Direction seule.
        $liste = $this->agirEn($direction)->getJson('/api/bibliotheque-prix?orphelines=1')->assertOk();
        $this->assertSame(['ORPH-1'], $this->codes($liste));
        $this->assertTrue($liste->json('data.0.est_orpheline'));
        $this->agirEn($commercial)->getJson('/api/bibliotheque-prix?orphelines=1')->assertForbidden();
        $this->agirEn($collegue)->getJson('/api/bibliotheque-prix?orphelines=1')->assertForbidden();

        // Consultation directe : Direction oui, les autres 404.
        $this->agirEn($direction)->getJson('/api/bibliotheque-prix/' . $id)->assertOk();
        $this->agirEn($collegue)->getJson('/api/bibliotheque-prix/' . $id)->assertNotFound();
        $this->agirEn($commercial)->getJson('/api/bibliotheque-prix/' . $id)->assertNotFound();

        // La Direction la publie pour l'entreprise ...
        $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix/' . $id . '/publier')
            ->assertOk()
            ->assertJsonPath('portee', 'entreprise');
        $this->assertSame(['ORPH-1'], $this->codes($this->agirEn($collegue)->getJson('/api/bibliotheque-prix')));
    }

    #[Test]
    public function la_direction_archive_une_version_orpheline(): void
    {
        $this->activerPourLeCommercial();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $id = $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'ORPH-2']))
            ->json('id');
        DB::table('articles_prix')->where('id', $id)->update(['proprietaire_id' => null]);

        $this->agirEn($direction)->deleteJson('/api/bibliotheque-prix/' . $id)->assertNoContent();

        $this->assertSame([], $this->codes($this->agirEn($direction)->getJson('/api/bibliotheque-prix?orphelines=1')));
    }

    #[Test]
    public function les_codes_automatiques_se_suivent_par_prefixe_et_par_entreprise(): void
    {
        $categorie = CategoriePrix::creerReference(['niveau' => 2, 'ordre' => 4, 'code' => '1.4', 'nom' => 'Carrelage']);
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $this->agirEn($direction)->postJson('/api/bibliotheque-prix', $this->donnees())
            ->assertCreated()->assertJsonPath('reference', 'SC-0001');
        $deuxieme = $this->agirEn($direction)->postJson('/api/bibliotheque-prix', $this->donnees())
            ->assertCreated()->assertJsonPath('reference', 'SC-0002');
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix', $this->donnees(['categorie_id' => $categorie->id]))
            ->assertCreated()->assertJsonPath('reference', '1.4-0001');

        // Un code archive reste pris : la numerotation ne le reutilise pas.
        $this->agirEn($direction)->deleteJson('/api/bibliotheque-prix/' . $deuxieme->json('id'))->assertNoContent();
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix', $this->donnees())
            ->assertCreated()->assertJsonPath('reference', 'SC-0003');

        // Un code saisi a la main est respecte, et ne perturbe pas la suite.
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'SC-0010']))
            ->assertCreated()->assertJsonPath('reference', 'SC-0010');
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix', $this->donnees())
            ->assertCreated()->assertJsonPath('reference', 'SC-0011');

        // Une autre entreprise repart de 0001.
        $autre = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');
        $this->agirEn($autre)->postJson('/api/bibliotheque-prix', $this->donnees())
            ->assertCreated()->assertJsonPath('reference', 'SC-0001');
    }

    #[Test]
    public function le_proprietaire_archive_sa_version_privee_mais_pas_celle_d_un_autre(): void
    {
        $this->activerPourLeCommercial();
        $commercial = $this->creerUtilisateur($this->entreprise, 'Commercial');
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $idEntreprise = $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'ENT-9']))
            ->json('id');
        $idPrive = $this->agirEn($commercial)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'PRIV-9']))
            ->json('id');

        $this->agirEn($commercial)->deleteJson('/api/bibliotheque-prix/' . $idEntreprise)->assertForbidden();
        $this->agirEn($commercial)->deleteJson('/api/bibliotheque-prix/' . $idPrive)->assertNoContent();
        $this->assertSoftDeleted('articles_prix', ['id' => $idPrive]);
    }
}
