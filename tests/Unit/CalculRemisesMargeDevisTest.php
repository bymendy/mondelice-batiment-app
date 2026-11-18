<?php

namespace Tests\Unit;

use App\Models\DeductionDevis;
use App\Models\LigneDevis;
use App\Services\Devis\CalculTotauxDevis;
use Tests\TestCase;

/**
 * Module Devis, lot D4a : remises et marge d'un devis (sans base de donnees).
 */
class CalculRemisesMargeDevisTest extends TestCase
{
    /**
     * @param float $quantite
     * @param float $prix
     * @param float $taux
     * @param int|null $lot
     * @param float $remise Remise de la ligne, en pourcentage.
     * @param float|null $revient Prix de revient unitaire.
     * @return LigneDevis
     */
    private function ligne(float $quantite, float $prix, float $taux = 20.0, ?int $lot = null, float $remise = 0.0, ?float $revient = null): LigneDevis
    {
        return new LigneDevis([
            'quantite' => $quantite,
            'prix_unitaire_ht' => $prix,
            'taux_tva' => $taux,
            'lot_id' => $lot,
            'remise_pct' => $remise,
            'prix_revient_unitaire' => $revient,
        ]);
    }

    /**
     * @param string $type pourcentage ou montant.
     * @param float $valeur
     * @param int|null $lot
     * @return DeductionDevis
     */
    private function remise(string $type, float $valeur, ?int $lot = null): DeductionDevis
    {
        return new DeductionDevis(['libelle' => 'Remise', 'type' => $type, 'valeur' => $valeur, 'lot_id' => $lot]);
    }

    public function test_la_remise_d_une_ligne(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([$this->ligne(2, 100, 20, null, 10)]);

        $this->assertSame(200.0, $totaux['total_ht_brut']);
        $this->assertSame(180.0, $totaux['total_ht']);
        $this->assertSame(36.0, $totaux['total_tva']);
        $this->assertSame(216.0, $totaux['total_ttc']);
        $this->assertSame(20.0, $totaux['total_remises']);
        $this->assertSame(20.0, $totaux['remises']['lignes']);
    }

    public function test_la_remise_d_un_lot_ne_touche_que_ce_lot(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer(
            [$this->ligne(1, 100, 20, 1), $this->ligne(1, 50, 20, 1), $this->ligne(1, 200, 20, 2)],
            [$this->remise('pourcentage', 10, 1)]
        );

        $this->assertSame(335.0, $totaux['total_ht']);
        $this->assertSame(15.0, $totaux['remises']['lots']);
        $this->assertSame(
            [['lot_id' => 1, 'total_ht' => 135.0], ['lot_id' => 2, 'total_ht' => 200.0]],
            $totaux['par_lot']
        );
        $this->assertSame(150.0, $totaux['remises']['detail'][0]['base_ht']);
        $this->assertSame(15.0, $totaux['remises']['detail'][0]['montant']);
    }

    public function test_la_remise_globale_est_repartie_au_prorata_des_taux_de_tva(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer(
            [$this->ligne(1, 100, 20), $this->ligne(1, 50, 10)],
            [$this->remise('montant', 30)]
        );

        $this->assertSame(120.0, $totaux['total_ht']);
        $this->assertSame(
            [
                ['taux' => 20.0, 'base_ht' => 80.0, 'tva' => 16.0],
                ['taux' => 10.0, 'base_ht' => 40.0, 'tva' => 4.0],
            ],
            $totaux['par_taux']
        );
        $this->assertSame(20.0, $totaux['total_tva']);
        $this->assertSame(140.0, $totaux['total_ttc']);
        $this->assertSame(30.0, $totaux['remises']['globale']);
    }

    public function test_la_repartition_ne_perd_aucun_centime(): void
    {
        // 10 EUR sur trois bases egales : 3,33 + 3,33 + 3,34.
        $totaux = (new CalculTotauxDevis())->calculer(
            [$this->ligne(1, 100, 20), $this->ligne(1, 100, 10), $this->ligne(1, 100, 5.5)],
            [$this->remise('montant', 10)]
        );

        $this->assertSame(290.0, $totaux['total_ht']);
        $bases = array_column($totaux['par_taux'], 'base_ht');
        sort($bases);
        $this->assertSame([96.66, 96.67, 96.67], $bases);
    }

    public function test_l_ordre_est_ligne_puis_lot_puis_globale(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer(
            [$this->ligne(1, 100, 20, 1, 10)],
            [$this->remise('pourcentage', 10), $this->remise('pourcentage', 10, 1)]
        );

        // 100 -> 90 (ligne) -> 81 (lot) -> 72,90 (globale).
        $this->assertSame(72.9, $totaux['total_ht']);
        $this->assertSame(10.0, $totaux['remises']['lignes']);
        $this->assertSame(9.0, $totaux['remises']['lots']);
        $this->assertSame(8.1, $totaux['remises']['globale']);
        $this->assertSame(27.1, $totaux['total_remises']);
    }

    public function test_une_remise_plus_forte_que_sa_base_est_plafonnee(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([$this->ligne(1, 50, 20)], [$this->remise('montant', 80)]);

        $this->assertSame(0.0, $totaux['total_ht']);
        $this->assertSame(50.0, $totaux['remises']['detail'][0]['montant']);
        $this->assertTrue($totaux['remises']['detail'][0]['plafonnee']);
    }

    public function test_sans_remise_rien_n_est_plafonne(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([$this->ligne(1, 50, 20)], [$this->remise('pourcentage', 100)]);

        $this->assertSame(0.0, $totaux['total_ht']);
        $this->assertFalse($totaux['remises']['detail'][0]['plafonnee']);
    }

    public function test_la_marge_d_un_devis_complet(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([
            $this->ligne(2, 100, 20, null, 0, 60),
            $this->ligne(1, 50, 20, null, 0, 30),
        ]);

        $this->assertSame(150.0, $totaux['marge']['revient_ht']);
        $this->assertTrue($totaux['marge']['revient_complet']);
        $this->assertSame(100.0, $totaux['marge']['marge_ht']);
        $this->assertSame(40.0, $totaux['marge']['taux_marge_pct']);
        $this->assertSame(1.67, $totaux['marge']['coefficient']);
    }

    public function test_la_marge_se_calcule_apres_les_remises(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer(
            [$this->ligne(2, 100, 20, null, 0, 60), $this->ligne(1, 50, 20, null, 0, 30)],
            [$this->remise('pourcentage', 10)]
        );

        $this->assertSame(225.0, $totaux['total_ht']);
        $this->assertSame(75.0, $totaux['marge']['marge_ht']);
        $this->assertSame(33.33, $totaux['marge']['taux_marge_pct']);
        $this->assertSame(1.5, $totaux['marge']['coefficient']);
    }

    public function test_une_ligne_sans_revient_rend_la_marge_incomplete(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([
            $this->ligne(2, 100, 20, null, 0, 60),
            $this->ligne(1, 50, 20),
        ]);

        $this->assertFalse($totaux['marge']['revient_complet']);
        $this->assertSame(120.0, $totaux['marge']['revient_ht']);
        $this->assertNull($totaux['marge']['marge_ht']);
        $this->assertNull($totaux['marge']['taux_marge_pct']);
        $this->assertNull($totaux['marge']['coefficient']);
    }

    public function test_la_marge_par_lot(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer(
            [
                $this->ligne(1, 100, 20, 1, 0, 50),
                $this->ligne(1, 200, 20, 2, 0, 150),
                $this->ligne(1, 40, 20, 2),
            ],
            [$this->remise('pourcentage', 10, 1)]
        );

        $lots = $totaux['marge']['par_lot'];
        $this->assertSame(1, $lots[0]['lot_id']);
        $this->assertSame(90.0, $lots[0]['total_ht']);
        $this->assertSame(40.0, $lots[0]['marge_ht']);
        $this->assertSame(44.44, $lots[0]['taux_marge_pct']);
        $this->assertSame(1.8, $lots[0]['coefficient']);

        $this->assertSame(2, $lots[1]['lot_id']);
        $this->assertFalse($lots[1]['revient_complet']);
        $this->assertNull($lots[1]['marge_ht']);
        $this->assertFalse($totaux['marge']['revient_complet']);
    }
}
