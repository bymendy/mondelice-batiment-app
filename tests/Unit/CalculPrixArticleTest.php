<?php

namespace Tests\Unit;

use App\Enums\TypeComposant;
use App\Exceptions\QualificationInconnueException;
use App\Models\ArticleComposant;
use App\Models\TauxHoraire;
use App\Services\Bibliotheque\CalculPrixArticle;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Calcul du prix d'un article (service CalculPrixArticle) : aucun acces base.
 */
class CalculPrixArticleTest extends TestCase
{
    /**
     * @param string $code
     * @param float $vente
     * @param float|null $revient
     * @return TauxHoraire
     */
    private function taux(string $code, float $vente, ?float $revient): TauxHoraire
    {
        return new TauxHoraire([
            'code' => $code,
            'libelle' => ucfirst($code),
            'taux_vente_ht' => $vente,
            'cout_revient_ht' => $revient,
        ]);
    }

    /**
     * @param float $heures
     * @param string $qualification
     * @return ArticleComposant
     */
    private function mo(float $heures, string $qualification = 'ouvrier'): ArticleComposant
    {
        return new ArticleComposant([
            'type' => TypeComposant::MainOeuvre,
            'qualification' => $qualification,
            'designation' => 'Main d\'oeuvre',
            'quantite' => $heures,
            'unite' => 'h',
        ]);
    }

    /**
     * @param float $quantite
     * @param float $vente
     * @param float|null $achat
     * @param TypeComposant $type
     * @return ArticleComposant
     */
    private function fourniture(float $quantite, float $vente, ?float $achat, TypeComposant $type = TypeComposant::FournitureLibre): ArticleComposant
    {
        return new ArticleComposant([
            'type' => $type,
            'designation' => 'Fourniture',
            'quantite' => $quantite,
            'unite' => 'U',
            'prix_vente_ht' => $vente,
            'prix_achat_ht' => $achat,
        ]);
    }

    /**
     * @return array<string, TauxHoraire>
     */
    private function tauxEntreprise(): array
    {
        return [
            'ouvrier' => $this->taux('ouvrier', 65.00, 40.00),
            'chef' => $this->taux('chef', 62.00, 35.00),
        ];
    }

    #[Test]
    public function l_exemple_du_document_donne_74_75_euros(): void
    {
        // 1,15 h x 65,00 EUR = 74,75 EUR = prix unitaire de l'article.
        $resultat = (new CalculPrixArticle())->calculer([$this->mo(1.15)], $this->tauxEntreprise());

        $this->assertSame(74.75, $resultat->prixVenteHt);
        $this->assertSame(74.75, $resultat->prixVenteSousDetailHt);
        $this->assertNull($resultat->prixForceHt);
        $this->assertNull($resultat->ecartPrixForceHt);
    }

    #[Test]
    public function la_main_d_oeuvre_et_les_fournitures_s_additionnent_avec_revient_marge_et_coefficient(): void
    {
        $composants = [$this->mo(2, 'chef'), $this->fourniture(3, 10.00, 6.00)];

        $resultat = (new CalculPrixArticle())->calculer($composants, $this->tauxEntreprise());

        $this->assertSame(154.00, $resultat->prixVenteHt);      // 2 x 62 + 3 x 10
        $this->assertSame(88.00, $resultat->prixRevientHt);     // 2 x 35 + 3 x 6
        $this->assertTrue($resultat->revientComplet);
        $this->assertSame(66.00, $resultat->margeHt);
        $this->assertSame(1.75, $resultat->coefficient);
        $this->assertSame(42.86, $resultat->tauxMargePct);
    }

    #[Test]
    public function le_prix_force_prime_et_l_ecart_avec_le_sous_detail_est_affiche(): void
    {
        $composants = [$this->mo(2, 'chef'), $this->fourniture(3, 10.00, 6.00)];

        $resultat = (new CalculPrixArticle())->calculer($composants, $this->tauxEntreprise(), 140.00);

        $this->assertSame(140.00, $resultat->prixVenteHt);
        $this->assertSame(154.00, $resultat->prixVenteSousDetailHt);
        $this->assertSame(140.00, $resultat->prixForceHt);
        $this->assertSame(-14.00, $resultat->ecartPrixForceHt);
        $this->assertSame(52.00, $resultat->margeHt);            // la marge suit le prix retenu
        $this->assertSame(1.59, $resultat->coefficient);
        $this->assertSame(37.14, $resultat->tauxMargePct);
    }

    #[Test]
    public function un_changement_de_taux_horaire_change_le_prix_de_l_article(): void
    {
        $composants = [$this->mo(2, 'ouvrier')];
        $service = new CalculPrixArticle();

        $avant = $service->calculer($composants, ['ouvrier' => $this->taux('ouvrier', 50.00, 30.00)]);
        $apres = $service->calculer($composants, ['ouvrier' => $this->taux('ouvrier', 58.00, 30.00)]);

        $this->assertSame(100.00, $avant->prixVenteHt);
        $this->assertSame(116.00, $apres->prixVenteHt);
    }

    #[Test]
    public function un_prix_d_achat_manquant_rend_le_revient_incomplet_sans_marge_trompeuse(): void
    {
        $composants = [$this->fourniture(1, 20.00, null), $this->fourniture(1, 10.00, 4.00)];

        $resultat = (new CalculPrixArticle())->calculer($composants, []);

        $this->assertSame(30.00, $resultat->prixVenteHt);
        $this->assertFalse($resultat->revientComplet);
        $this->assertSame(4.00, $resultat->prixRevientHt);
        $this->assertNull($resultat->margeHt);
        $this->assertNull($resultat->coefficient);
        $this->assertNull($resultat->tauxMargePct);
    }

    #[Test]
    public function un_taux_sans_cout_de_revient_rend_le_revient_incomplet(): void
    {
        $resultat = (new CalculPrixArticle())->calculer(
            [$this->mo(1)],
            ['ouvrier' => $this->taux('ouvrier', 65.00, null)]
        );

        $this->assertSame(65.00, $resultat->prixVenteHt);
        $this->assertFalse($resultat->revientComplet);
        $this->assertNull($resultat->margeHt);
    }

    #[Test]
    public function une_qualification_inconnue_leve_une_exception(): void
    {
        $this->expectException(QualificationInconnueException::class);

        (new CalculPrixArticle())->calculer([$this->mo(1, 'electricien')], $this->tauxEntreprise());
    }

    #[Test]
    public function le_coefficient_est_absent_quand_le_revient_est_nul(): void
    {
        $resultat = (new CalculPrixArticle())->calculer([$this->fourniture(1, 15.00, 0.00)], []);

        $this->assertTrue($resultat->revientComplet);
        $this->assertSame(0.00, $resultat->prixRevientHt);
        $this->assertSame(15.00, $resultat->margeHt);
        $this->assertNull($resultat->coefficient);
        $this->assertSame(100.00, $resultat->tauxMargePct);
    }

    #[Test]
    public function l_arrondi_se_fait_une_seule_fois_sur_le_total(): void
    {
        // 0,3333 x 10 = 3,333 -> 3,33 ; deux lignes : 6,666 -> 6,67 (et non 3,33 + 3,33 = 6,66).
        $composants = [$this->fourniture(0.3333, 10.00, 1.00), $this->fourniture(0.3333, 10.00, 1.00)];

        $resultat = (new CalculPrixArticle())->calculer($composants, []);

        $this->assertSame(6.67, $resultat->prixVenteHt);
    }

    #[Test]
    public function materiel_frais_et_fourniture_de_la_base_se_calculent_comme_une_fourniture(): void
    {
        $composants = [
            $this->fourniture(1, 12.00, 8.00, TypeComposant::Materiel),
            $this->fourniture(2, 5.00, 3.00, TypeComposant::Frais),
            $this->fourniture(4, 2.50, 1.50, TypeComposant::FournitureBase),
        ];

        $resultat = (new CalculPrixArticle())->calculer($composants, []);

        $this->assertSame(32.00, $resultat->prixVenteHt);   // 12 + 10 + 10
        $this->assertSame(20.00, $resultat->prixRevientHt); // 8 + 6 + 6
    }

    #[Test]
    public function un_article_sans_sous_detail_vaut_zero_ou_son_prix_force(): void
    {
        $service = new CalculPrixArticle();

        $this->assertSame(0.00, $service->calculer([], [])->prixVenteHt);
        $this->assertSame(25.00, $service->calculer([], [], 25.00)->prixVenteHt);
    }
}
