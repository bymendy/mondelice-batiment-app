<?php

namespace Tests\Feature;

use App\Enums\UniteArticle;
use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\Tenant;
use App\Models\TauxHoraire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Taux horaires et sous-detail des articles (lot 2) : structure, contraintes
 * en base et cloisonnement entre entreprises.
 */
class TauxHorairesEtComposantsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    /**
     * Insere un article directement en base (sans passer par le modele) et
     * renvoie son identifiant.
     *
     * @param Tenant $entreprise
     * @return int
     */
    private function creerArticle(Tenant $entreprise): int
    {
        return DB::table('articles_prix')->insertGetId([
            'tenant_id' => $entreprise->getTenantKey(),
            'reference' => Str::random(10),
            'designation' => 'Article de test',
            'categorie' => 'materiaux',
            'unite' => UniteArticle::cases()[0]->value,
            'prix_vente_ht' => 10,
            'taux_tva' => 20,
            'actif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $surcharge
     * @return array<string, mixed>
     */
    private function attributsTaux(array $surcharge = []): array
    {
        return array_merge(
            ['code' => 'ouvrier', 'libelle' => 'Ouvrier', 'taux_vente_ht' => 50, 'cout_revient_ht' => 30, 'ordre' => 1],
            $surcharge
        );
    }

    /**
     * @param int $articleId
     * @param array<string, mixed> $surcharge
     * @return array<string, mixed>
     */
    private function attributsMainOeuvre(int $articleId, array $surcharge = []): array
    {
        return array_merge([
            'article_id' => $articleId,
            'type' => 'main_oeuvre',
            'qualification' => 'ouvrier',
            'designation' => 'Main d\'oeuvre',
            'quantite' => 1.15,
            'unite' => 'h',
            'ordre' => 1,
        ], $surcharge);
    }

    /**
     * @param int $articleId
     * @param array<string, mixed> $surcharge
     * @return array<string, mixed>
     */
    private function attributsFourniture(int $articleId, array $surcharge = []): array
    {
        return array_merge([
            'article_id' => $articleId,
            'type' => 'fourniture_libre',
            'designation' => 'Plaque',
            'quantite' => 2,
            'unite' => 'U',
            'prix_achat_ht' => 5,
            'prix_vente_ht' => 8,
            'ordre' => 2,
        ], $surcharge);
    }

    /**
     * Verifie qu'une creation est refusee par la base, sans casser la transaction du test.
     *
     * @param callable $creation
     * @param string $contrainte Nom (ou morceau) de la contrainte attendue dans le message.
     * @return void
     */
    private function assertRefuseeParLaBase(callable $creation, string $contrainte): void
    {
        try {
            DB::transaction($creation);
            $this->fail("La base aurait du refuser ({$contrainte}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($contrainte, $e->getMessage());
        }
    }

    #[Test]
    public function deux_entreprises_peuvent_avoir_la_meme_qualification(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();

        $this->dansEntreprise($a, fn () => TauxHoraire::create($this->attributsTaux()));
        $this->dansEntreprise($b, fn () => TauxHoraire::create($this->attributsTaux(['taux_vente_ht' => 62])));

        $this->assertSame(2, TauxHoraire::sansFiltreTenant()->where('code', 'ouvrier')->count());
    }

    #[Test]
    public function une_entreprise_ne_voit_pas_les_taux_des_autres(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();

        $this->dansEntreprise($a, fn () => TauxHoraire::create($this->attributsTaux(['code' => 'ouvrier'])));
        $this->dansEntreprise($b, fn () => TauxHoraire::create($this->attributsTaux(['code' => 'technicien'])));

        $this->assertSame(['ouvrier'], $this->dansEntreprise($a, fn () => TauxHoraire::pluck('code')->all()));
        $this->assertSame(['technicien'], $this->dansEntreprise($b, fn () => TauxHoraire::pluck('code')->all()));
    }

    #[Test]
    public function un_code_en_double_dans_la_meme_entreprise_est_refuse(): void
    {
        $a = $this->creerEntreprise();
        $this->dansEntreprise($a, fn () => TauxHoraire::create($this->attributsTaux()));

        $this->assertRefuseeParLaBase(
            fn () => $this->dansEntreprise($a, fn () => TauxHoraire::create($this->attributsTaux(['libelle' => 'Doublon']))),
            'taux_horaires_tenant_id_code_unique'
        );
    }

    #[Test]
    public function un_seul_taux_par_defaut_par_entreprise(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();

        $premier = $this->dansEntreprise($a, fn () => TauxHoraire::create($this->attributsTaux(['par_defaut' => true])));

        // Une autre entreprise a son propre taux par defaut.
        $this->dansEntreprise($b, fn () => TauxHoraire::create($this->attributsTaux(['par_defaut' => true])));

        // Un second taux par defaut dans la meme entreprise est refuse.
        $this->assertRefuseeParLaBase(
            fn () => $this->dansEntreprise($a, fn () => TauxHoraire::create(
                $this->attributsTaux(['code' => 'technicien', 'libelle' => 'Technicien', 'par_defaut' => true])
            )),
            'taux_horaires_un_defaut_par_tenant'
        );

        // Apres avoir retire le defaut du premier, un autre peut le devenir.
        $this->dansEntreprise($a, function () use ($premier): void {
            TauxHoraire::findOrFail($premier->id)->update(['par_defaut' => false]);
            TauxHoraire::create($this->attributsTaux(['code' => 'technicien', 'libelle' => 'Technicien', 'par_defaut' => true]));
            $this->assertSame(1, TauxHoraire::where('par_defaut', true)->count());
        });
    }

    #[Test]
    public function indexes_par_code_renvoie_les_taux_de_l_entreprise_courante(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();

        $this->dansEntreprise($a, function (): void {
            TauxHoraire::create($this->attributsTaux(['code' => 'ouvrier', 'ordre' => 1]));
            TauxHoraire::create($this->attributsTaux(['code' => 'chef', 'libelle' => 'Chef', 'ordre' => 2]));
        });
        $this->dansEntreprise($b, fn () => TauxHoraire::create($this->attributsTaux(['code' => 'autre'])));

        $indexes = $this->dansEntreprise($a, fn () => TauxHoraire::indexesParCode());

        $this->assertSame(['ouvrier', 'chef'], array_keys($indexes));
    }

    #[Test]
    public function une_ligne_de_main_d_oeuvre_valide_est_acceptee(): void
    {
        $a = $this->creerEntreprise();
        $articleId = $this->creerArticle($a);

        $composant = $this->dansEntreprise($a, fn () => ArticleComposant::create($this->attributsMainOeuvre($articleId)));

        $this->assertNotNull($composant->id);
        $this->assertSame($a->getTenantKey(), $composant->tenant_id);
    }

    #[Test]
    public function la_main_d_oeuvre_n_accepte_aucun_prix(): void
    {
        $a = $this->creerEntreprise();
        $articleId = $this->creerArticle($a);

        $this->assertRefuseeParLaBase(
            fn () => $this->dansEntreprise($a, fn () => ArticleComposant::create(
                $this->attributsMainOeuvre($articleId, ['prix_vente_ht' => 65])
            )),
            'article_composants_main_oeuvre_check'
        );
    }

    #[Test]
    public function la_main_d_oeuvre_exige_une_qualification_et_l_unite_heure(): void
    {
        $a = $this->creerEntreprise();
        $articleId = $this->creerArticle($a);

        $this->assertRefuseeParLaBase(
            fn () => $this->dansEntreprise($a, fn () => ArticleComposant::create(
                $this->attributsMainOeuvre($articleId, ['qualification' => null])
            )),
            'article_composants_main_oeuvre_check'
        );

        $this->assertRefuseeParLaBase(
            fn () => $this->dansEntreprise($a, fn () => ArticleComposant::create(
                $this->attributsMainOeuvre($articleId, ['unite' => 'U'])
            )),
            'article_composants_main_oeuvre_check'
        );
    }

    #[Test]
    public function une_fourniture_exige_un_prix_de_vente(): void
    {
        $a = $this->creerEntreprise();
        $articleId = $this->creerArticle($a);

        $this->assertRefuseeParLaBase(
            fn () => $this->dansEntreprise($a, fn () => ArticleComposant::create(
                $this->attributsFourniture($articleId, ['prix_vente_ht' => null])
            )),
            'article_composants_prix_check'
        );
    }

    #[Test]
    public function une_fourniture_peut_n_avoir_aucun_prix_d_achat(): void
    {
        $a = $this->creerEntreprise();
        $articleId = $this->creerArticle($a);

        $composant = $this->dansEntreprise($a, fn () => ArticleComposant::create(
            $this->attributsFourniture($articleId, ['prix_achat_ht' => null])
        ));

        $this->assertNull($composant->prix_achat_ht);
    }

    #[Test]
    public function une_quantite_negative_est_refusee(): void
    {
        $a = $this->creerEntreprise();
        $articleId = $this->creerArticle($a);

        $this->assertRefuseeParLaBase(
            fn () => $this->dansEntreprise($a, fn () => ArticleComposant::create(
                $this->attributsFourniture($articleId, ['quantite' => -1])
            )),
            'article_composants_quantite_check'
        );
    }

    #[Test]
    public function une_entreprise_ne_voit_pas_les_composants_des_autres(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();
        $articleA = $this->creerArticle($a);
        $articleB = $this->creerArticle($b);

        $this->dansEntreprise($a, fn () => ArticleComposant::create($this->attributsFourniture($articleA, ['designation' => 'De A'])));
        $this->dansEntreprise($b, fn () => ArticleComposant::create($this->attributsFourniture($articleB, ['designation' => 'De B'])));

        $this->assertSame(['De A'], $this->dansEntreprise($a, fn () => ArticleComposant::pluck('designation')->all()));
        $this->assertSame(['De B'], $this->dansEntreprise($b, fn () => ArticleComposant::pluck('designation')->all()));
    }

    #[Test]
    public function un_composant_ne_peut_pas_pointer_vers_l_article_d_une_autre_entreprise(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();
        $articleDeB = $this->creerArticle($b);

        $this->expectException(LogicException::class);

        $this->dansEntreprise($a, fn () => ArticleComposant::create($this->attributsFourniture($articleDeB)));
    }

    #[Test]
    public function un_composant_ne_peut_pas_pointer_vers_un_article_inexistant(): void
    {
        $a = $this->creerEntreprise();

        $this->expectException(LogicException::class);

        $this->dansEntreprise($a, fn () => ArticleComposant::create($this->attributsFourniture(999999)));
    }

    #[Test]
    public function un_composant_ne_peut_pas_etre_deplace_vers_l_article_d_une_autre_entreprise(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();
        $articleA = $this->creerArticle($a);
        $articleB = $this->creerArticle($b);

        $composant = $this->dansEntreprise($a, fn () => ArticleComposant::create($this->attributsFourniture($articleA)));

        $this->expectException(LogicException::class);

        $this->dansEntreprise($a, fn () => ArticleComposant::findOrFail($composant->id)->update(['article_id' => $articleB]));
    }

    #[Test]
    public function la_relation_composants_de_l_article_est_triee_par_ordre(): void
    {
        $a = $this->creerEntreprise();
        $articleId = $this->creerArticle($a);

        $this->dansEntreprise($a, function () use ($articleId): void {
            ArticleComposant::create($this->attributsFourniture($articleId, ['designation' => 'Second', 'ordre' => 2]));
            ArticleComposant::create($this->attributsMainOeuvre($articleId, ['designation' => 'Premier', 'ordre' => 1]));
        });

        $designations = $this->dansEntreprise($a, fn () => ArticlePrix::findOrFail($articleId)->composants->pluck('designation')->all());

        $this->assertSame(['Premier', 'Second'], $designations);
    }
}
