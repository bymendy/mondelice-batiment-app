<?php

namespace Tests\Unit;

use App\Models\LigneDevis;
use App\Services\Devis\CalculTotauxDevis;
use Tests\TestCase;

/**
 * Module Devis, lot D0 : totaux d'un devis (sans base de donnees).
 */
class CalculTotauxDevisTest extends TestCase
{
    /**
     * @param float $quantite
     * @param float $prix
     * @param float $taux
     * @param int|null $lot
     * @return LigneDevis
     */
    private function ligne(float $quantite, float $prix, float $taux = 20.0, ?int $lot = null): LigneDevis
    {
        return new LigneDevis([
            'quantite' => $quantite,
            'prix_unitaire_ht' => $prix,
            'taux_tva' => $taux,
            'lot_id' => $lot,
        ]);
    }

    public function test_une_ligne_donne_ht_tva_et_ttc(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([$this->ligne(2, 74.75, 10)]);

        $this->assertSame(149.5, $totaux['total_ht']);
        $this->assertSame(14.95, $totaux['total_tva']);
        $this->assertSame(164.45, $totaux['total_ttc']);
    }

    public function test_la_tva_se_recapitule_par_taux(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([
            $this->ligne(1, 100, 20),
            $this->ligne(1, 50, 10),
            $this->ligne(2, 25, 20),
        ]);

        $this->assertSame(200.0, $totaux['total_ht']);
        $this->assertSame(
            [
                ['taux' => 20.0, 'base_ht' => 150.0, 'tva' => 30.0],
                ['taux' => 10.0, 'base_ht' => 50.0, 'tva' => 5.0],
            ],
            $totaux['par_taux']
        );
        $this->assertSame(35.0, $totaux['total_tva']);
        $this->assertSame(235.0, $totaux['total_ttc']);
    }

    public function test_la_tva_est_arrondie_sur_la_base_du_taux_et_pas_ligne_par_ligne(): void
    {
        // Trois lignes de 0,10 a 5,5 % : ligne par ligne 3 x 0,01 = 0,03 ; sur la base 0,30 -> 0,02.
        $totaux = (new CalculTotauxDevis())->calculer([
            $this->ligne(1, 0.10, 5.5),
            $this->ligne(1, 0.10, 5.5),
            $this->ligne(1, 0.10, 5.5),
        ]);

        $this->assertSame(0.3, $totaux['total_ht']);
        $this->assertSame(0.02, $totaux['total_tva']);
        $this->assertSame(0.32, $totaux['total_ttc']);
    }

    public function test_chaque_ligne_est_arrondie_au_centime_a_la_hausse_a_la_moitie(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([$this->ligne(0.5, 3.01, 0)]);

        $this->assertSame(1.51, $totaux['total_ht']);
        $this->assertSame(0.0, $totaux['total_tva']);
    }

    public function test_un_devis_sans_ligne_vaut_zero(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([]);

        $this->assertSame(0.0, $totaux['total_ht']);
        $this->assertSame(0.0, $totaux['total_ttc']);
        $this->assertSame([], $totaux['par_taux']);
        $this->assertSame([], $totaux['par_lot']);
    }

    public function test_une_quantite_negative_est_une_deduction(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([
            $this->ligne(2, 50, 20),
            $this->ligne(-1, 50, 20),
        ]);

        $this->assertSame(50.0, $totaux['total_ht']);
        $this->assertSame(10.0, $totaux['total_tva']);
        $this->assertSame(60.0, $totaux['total_ttc']);
    }

    public function test_les_sous_totaux_par_lot(): void
    {
        $totaux = (new CalculTotauxDevis())->calculer([
            $this->ligne(1, 10, 20, 1),
            $this->ligne(1, 5, 20, 1),
            $this->ligne(1, 3, 20, null),
            $this->ligne(1, 7, 20, 2),
        ]);

        $this->assertSame(
            [
                ['lot_id' => 1, 'total_ht' => 15.0],
                ['lot_id' => null, 'total_ht' => 3.0],
                ['lot_id' => 2, 'total_ht' => 7.0],
            ],
            $totaux['par_lot']
        );
    }
}
