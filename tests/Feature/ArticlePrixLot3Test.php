<?php

namespace Tests\Feature;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\CategoriePrix;
use App\Models\TauxHoraire;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Lot 3 de la Bibliotheque de prix : nouvelle structure d'article, prix
 * calcule, DTU de reference, prix d'achat et marges selon la permission,
 * articles de reference partages et en lecture seule.
 */
class ArticlePrixLot3Test extends TestCase
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
            'reference' => 'ART-1',
            'designation' => 'Pose de carrelage',
            'type_article' => 'main_oeuvre',
            'unite' => 'm2',
            'taux_tva' => 10,
        ], $surcharge);
    }

    /**
     * Cree, dans l'entreprise, un taux « ouvrier » et un article de 1,5 h de
     * main-d'oeuvre + 2 fournitures (8 EUR vente, 5 EUR achat).
     * Vente : 1,5 x 60 + 2 x 8 = 106,00. Revient : 1,5 x 40 + 2 x 5 = 70,00.
     *
     * @return int Identifiant de l'article.
     */
    private function articleAvecSousDetail(): int
    {
        return $this->dansEntreprise($this->entreprise, function (): int {
            TauxHoraire::create([
                'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 60,
                'cout_revient_ht' => 40, 'par_defaut' => true, 'ordre' => 1,
            ]);
            $article = ArticlePrix::create([
                'reference' => 'ART-SD', 'designation' => 'Article avec sous-detail',
                'type_article' => 'materiaux', 'unite' => 'u', 'taux_tva' => 20,
            ]);
            $article->composants()->create([
                'type' => 'main_oeuvre', 'qualification' => 'ouvrier', 'designation' => 'Main d oeuvre',
                'quantite' => 1.5, 'unite' => 'h', 'ordre' => 1,
            ]);
            $article->composants()->create([
                'type' => 'fourniture_libre', 'designation' => 'Colle', 'quantite' => 2,
                'unite' => 'u', 'prix_achat_ht' => 5, 'prix_vente_ht' => 8, 'ordre' => 2,
            ]);

            return $article->id;
        });
    }

    /**
     * Compare des montants JSON sans dependre de la forme (106 ou 106.0).
     *
     * @param \Illuminate\Testing\TestResponse $reponse
     * @param array<string, float|int|null> $attendus Chemin JSON => montant attendu.
     * @return void
     */
    private function assertMontants($reponse, array $attendus): void
    {
        foreach ($attendus as $chemin => $attendu) {
            $valeur = $reponse->json($chemin);

            if ($attendu === null) {
                $this->assertNull($valeur, $chemin);

                continue;
            }

            $this->assertEqualsWithDelta($attendu, $valeur, 0.001, $chemin);
        }
    }

    #[Test]
    public function la_direction_cree_un_article_avec_prix_force_et_dtu(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $reponse = $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees([
                'prix_force_ht' => 55.5,
                'dtu_reference' => 'NF DTU 31.2 P1-1',
                'dtu_lien' => 'https://exemple.fr/dtu',
            ]))
            ->assertCreated()
            ->assertJsonPath('dtu_reference', 'NF DTU 31.2 P1-1')
            ->assertJsonPath('portee', 'entreprise')
            ->assertJsonPath('est_reference', false);
        $this->assertMontants($reponse, ['prix_force_ht' => 55.5, 'prix_vente_ht' => 55.5]);

        $this->assertDatabaseHas('articles_prix', [
            'reference' => 'ART-1',
            'tenant_id' => $this->entreprise->getTenantKey(),
            'portee' => 'entreprise',
        ]);
        $this->assertNotNull(ArticlePrix::sansFiltreTenant()->where('reference', 'ART-1')->value('prix_mis_a_jour_le'));
    }

    #[Test]
    public function les_anciens_champs_sont_ignores_et_les_valeurs_invalides_refusees(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['type_article' => 'inconnu']))
            ->assertUnprocessable();

        $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['dtu_lien' => 'pas une adresse']))
            ->assertUnprocessable();

        $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['prix_force_ht' => -1]))
            ->assertUnprocessable();

        $reponse = $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees([
                'famille' => 'ancien champ', 'prix_vente_ht' => 99, 'prix_achat_ht' => 50,
            ]))
            ->assertCreated();
        $this->assertMontants($reponse, ['prix_force_ht' => null, 'prix_vente_ht' => 0]);
    }

    #[Test]
    public function la_categorie_doit_etre_une_reference_ou_de_l_entreprise(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $reference = CategoriePrix::creerReference(['niveau' => 1, 'ordre' => 1, 'code' => '1', 'nom' => 'Domaine']);
        $autre = $this->creerEntreprise();
        $categorieAutre = $this->dansEntreprise($autre, fn () => CategoriePrix::create(
            ['niveau' => 1, 'ordre' => 1, 'code' => 'X', 'nom' => 'Categorie d une autre entreprise']
        ));
        $categoriePropre = $this->dansEntreprise($this->entreprise, fn () => CategoriePrix::create(
            ['niveau' => 1, 'ordre' => 1, 'code' => 'P', 'nom' => 'Categorie propre']
        ));

        $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'C-REF', 'categorie_id' => $reference->id]))
            ->assertCreated()
            ->assertJsonPath('categorie_id', $reference->id);

        $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'C-PROPRE', 'categorie_id' => $categoriePropre->id]))
            ->assertCreated();

        $this->agirEn($direction)
            ->postJson('/api/bibliotheque-prix', $this->donnees(['reference' => 'C-AUTRE', 'categorie_id' => $categorieAutre->id]))
            ->assertUnprocessable();
    }

    #[Test]
    public function le_prix_est_calcule_depuis_le_sous_detail(): void
    {
        $id = $this->articleAvecSousDetail();
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $reponse = $this->agirEn($direction)->getJson('/api/bibliotheque-prix/' . $id)->assertOk();
        $this->assertMontants($reponse, [
            'prix_vente_ht' => 106, 'prix_vente_ttc' => 127.2, 'prix_revient_ht' => 70,
            'marge_ht' => 36, 'coefficient' => 1.51, 'taux_marge_pct' => 33.96,
        ]);
    }

    #[Test]
    public function un_prix_force_prime_et_son_ecart_est_affiche(): void
    {
        $id = $this->articleAvecSousDetail();
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $reponse = $this->agirEn($direction)
            ->putJson('/api/bibliotheque-prix/' . $id, ['prix_force_ht' => 120])
            ->assertOk();
        $this->assertMontants($reponse, [
            'prix_vente_ht' => 120, 'prix_vente_sous_detail_ht' => 106, 'ecart_prix_force_ht' => 14,
        ]);

        $reponse = $this->agirEn($direction)
            ->putJson('/api/bibliotheque-prix/' . $id, ['prix_force_ht' => null])
            ->assertOk();
        $this->assertMontants($reponse, ['prix_vente_ht' => 106, 'ecart_prix_force_ht' => null]);
    }

    #[Test]
    public function une_qualification_inconnue_donne_une_erreur_de_prix_et_pas_un_prix_devine(): void
    {
        $id = $this->dansEntreprise($this->entreprise, function (): int {
            $article = ArticlePrix::create([
                'reference' => 'ART-QI', 'designation' => 'Qualification inconnue',
                'type_article' => 'main_oeuvre', 'unite' => 'u', 'taux_tva' => 20,
            ]);
            $article->composants()->create([
                'type' => 'main_oeuvre', 'qualification' => 'inconnue', 'designation' => 'MO',
                'quantite' => 1, 'unite' => 'h', 'ordre' => 1,
            ]);

            return $article->id;
        });
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $this->agirEn($direction)
            ->getJson('/api/bibliotheque-prix/' . $id)
            ->assertOk()
            ->assertJsonPath('prix_vente_ht', null)
            ->assertJsonStructure(['erreur_prix']);
    }

    #[Test]
    public function le_commercial_l_administratif_et_le_conducteur_voient_prix_d_achat_et_marges(): void
    {
        $id = $this->articleAvecSousDetail();

        foreach (['Commercial', 'Administratif', 'Conducteur travaux'] as $role) {
            $reponse = $this->agirEn($this->creerUtilisateur($this->entreprise, $role))
                ->getJson('/api/bibliotheque-prix/' . $id)
                ->assertOk();
            $this->assertMontants($reponse, ['prix_revient_ht' => 70, 'marge_ht' => 36]);
        }
    }

    #[Test]
    public function sans_la_permission_prix_d_achat_revient_et_marges_sont_retires(): void
    {
        $id = $this->articleAvecSousDetail();
        $lecteur = $this->creerUtilisateur($this->entreprise, 'Salarié terrain');
        $lecteur->givePermissionTo('bibliotheque.consulter');

        $reponse = $this->agirEn($lecteur)->getJson('/api/bibliotheque-prix/' . $id);

        $reponse->assertOk();
        $this->assertMontants($reponse, ['prix_vente_ht' => 106]);
        $reponse->assertJsonMissingPath('prix_revient_ht');
        $reponse->assertJsonMissingPath('marge_ht');
        $reponse->assertJsonMissingPath('coefficient');
        $reponse->assertJsonMissingPath('taux_marge_pct');

        $liste = $this->agirEn($lecteur)->getJson('/api/bibliotheque-prix')->assertOk();
        $this->assertArrayNotHasKey('marge_ht', $liste->json('data.0'));
    }

    #[Test]
    public function un_article_de_reference_est_visible_mais_ni_modifiable_ni_archivable(): void
    {
        $reference = ArticlePrix::creerReference([
            'reference' => 'R-0001', 'designation' => 'Article de reference',
            'type_article' => 'materiaux', 'unite' => 'u', 'taux_tva' => 20,
            'prix_force_ht' => 12, 'source' => 'mondelice',
        ]);
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $this->assertNull(ArticlePrix::sansFiltreTenant()->find($reference->id)->portee);

        $this->agirEn($direction)
            ->getJson('/api/bibliotheque-prix')
            ->assertOk()
            ->assertJsonFragment(['reference' => 'R-0001', 'est_reference' => true]);

        $this->agirEn($direction)
            ->putJson('/api/bibliotheque-prix/' . $reference->id, ['designation' => 'Piratage'])
            ->assertForbidden();
        $this->agirEn($direction)->deleteJson('/api/bibliotheque-prix/' . $reference->id)->assertForbidden();

        $this->assertDatabaseHas('articles_prix', ['id' => $reference->id, 'designation' => 'Article de reference']);
    }

    #[Test]
    public function la_composition_d_une_reference_est_calculee_avec_les_taux_de_l_entreprise(): void
    {
        $reference = ArticlePrix::creerReference([
            'reference' => 'R-0002', 'designation' => 'Reference avec main d oeuvre',
            'type_article' => 'main_oeuvre', 'unite' => 'u', 'taux_tva' => 20,
        ]);
        ArticleComposant::creerReference([
            'article_id' => $reference->id, 'type' => 'main_oeuvre', 'qualification' => 'ouvrier',
            'designation' => 'Main d oeuvre', 'quantite' => 2, 'unite' => 'h', 'ordre' => 1,
        ]);
        $this->dansEntreprise($this->entreprise, fn () => TauxHoraire::create([
            'code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 50,
            'cout_revient_ht' => 30, 'par_defaut' => true, 'ordre' => 1,
        ]));
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');

        $reponse = $this->agirEn($direction)->getJson('/api/bibliotheque-prix/' . $reference->id)->assertOk();
        $this->assertMontants($reponse, ['prix_vente_ht' => 100, 'marge_ht' => 40]);
    }

    #[Test]
    public function une_entreprise_ne_peut_pas_ajouter_de_composant_a_une_reference(): void
    {
        $reference = ArticlePrix::creerReference([
            'reference' => 'R-0003', 'designation' => 'Reference',
            'type_article' => 'materiaux', 'unite' => 'u', 'taux_tva' => 20,
        ]);

        $this->expectException(LogicException::class);

        $this->dansEntreprise($this->entreprise, fn () => ArticleComposant::create([
            'article_id' => $reference->id, 'type' => 'fourniture_libre', 'designation' => 'Intrus',
            'quantite' => 1, 'unite' => 'u', 'prix_vente_ht' => 5, 'ordre' => 1,
        ]));
    }

    #[Test]
    public function deux_references_ne_peuvent_pas_porter_le_meme_code(): void
    {
        $attributs = [
            'reference' => 'R-DOUBLON', 'designation' => 'Reference',
            'type_article' => 'materiaux', 'unite' => 'u', 'taux_tva' => 20,
        ];
        ArticlePrix::creerReference($attributs);

        $this->expectException(QueryException::class);

        ArticlePrix::creerReference($attributs);
    }

    #[Test]
    public function un_code_est_unique_par_entreprise_mais_reutilisable_dans_une_autre(): void
    {
        $directionA = $this->creerUtilisateur($this->entreprise, 'Direction');
        $directionB = $this->creerUtilisateur($this->creerEntreprise(), 'Direction');

        $this->agirEn($directionA)->postJson('/api/bibliotheque-prix', $this->donnees())->assertCreated();
        $this->agirEn($directionA)->postJson('/api/bibliotheque-prix', $this->donnees())->assertUnprocessable();
        $this->agirEn($directionB)->postJson('/api/bibliotheque-prix', $this->donnees())->assertCreated();
    }

    #[Test]
    public function la_liste_se_filtre_par_type_et_par_recherche(): void
    {
        $direction = $this->creerUtilisateur($this->entreprise, 'Direction');
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix', $this->donnees())->assertCreated();
        $this->agirEn($direction)->postJson('/api/bibliotheque-prix', $this->donnees([
            'reference' => 'ART-2', 'designation' => 'Peinture murale', 'type_article' => 'materiaux',
        ]))->assertCreated();

        $this->agirEn($direction)
            ->getJson('/api/bibliotheque-prix?type_article=materiaux')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'ART-2');

        $this->agirEn($direction)
            ->getJson('/api/bibliotheque-prix?recherche=carrelage')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'ART-1');
    }
}
