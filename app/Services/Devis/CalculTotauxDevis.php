<?php

namespace App\Services\Devis;

use App\Services\Bibliotheque\Arrondi;

/**
 * Totaux d'un devis a partir de ses lignes.
 *
 * Regles :
 *  - le montant d'une ligne = quantite x prix unitaire HT, arrondi au centime
 *    (a la hausse a la moitie, comme dans la bibliotheque) ;
 *  - le total HT est la somme des montants de ligne affiches ;
 *  - la TVA se calcule par taux, sur la base HT de ce taux (somme des
 *    lignes), arrondie au centime ; le total de TVA est la somme des TVA des
 *    taux. Elle n'est pas arrondie ligne par ligne ;
 *  - TTC = HT + TVA ;
 *  - une quantite negative donne une ligne negative (deduction).
 *
 * Le service ne lit pas la base : il recoit des lignes (quantite,
 * prix_unitaire_ht, taux_tva, lot_id), ce qui le rend testable sans entreprise.
 */
class CalculTotauxDevis
{
    /**
     * @param iterable<object> $lignes Lignes avec quantite, prix_unitaire_ht, taux_tva et lot_id.
     * @return array{
     *     total_ht: float,
     *     total_tva: float,
     *     total_ttc: float,
     *     par_taux: list<array{taux: float, base_ht: float, tva: float}>,
     *     par_lot: list<array{lot_id: int|null, total_ht: float}>
     * }
     */
    public function calculer(iterable $lignes): array
    {
        $htCts = 0;
        $bases = [];
        $lots = [];

        foreach ($lignes as $ligne) {
            $montant = Arrondi::ligneEnCentimes($ligne->quantite, $ligne->prix_unitaire_ht);
            $taux100 = (int) round(((float) $ligne->taux_tva) * 100);

            $htCts += $montant;
            $bases[$taux100] = ($bases[$taux100] ?? 0) + $montant;

            $cle = $ligne->lot_id === null ? 'sans_lot' : (string) $ligne->lot_id;
            $lots[$cle] ??= ['lot_id' => $ligne->lot_id === null ? null : (int) $ligne->lot_id, 'cts' => 0];
            $lots[$cle]['cts'] += $montant;
        }

        krsort($bases);

        $tvaCts = 0;
        $parTaux = [];
        foreach ($bases as $taux100 => $base) {
            $tva = $this->tvaEnCentimes($base, $taux100);
            $tvaCts += $tva;
            $parTaux[] = [
                'taux' => $taux100 / 100.0,
                'base_ht' => Arrondi::euros($base),
                'tva' => Arrondi::euros($tva),
            ];
        }

        return [
            'total_ht' => Arrondi::euros($htCts),
            'total_tva' => Arrondi::euros($tvaCts),
            'total_ttc' => Arrondi::euros($htCts + $tvaCts),
            'par_taux' => $parTaux,
            'par_lot' => array_values(array_map(
                fn (array $lot): array => ['lot_id' => $lot['lot_id'], 'total_ht' => Arrondi::euros($lot['cts'])],
                $lots
            )),
        ];
    }

    /**
     * TVA d'une base, arrondie au centime (a la hausse a la moitie).
     *
     * @param int $baseCts Base HT en centimes.
     * @param int $taux100 Taux en centiemes de pour cent (550 = 5,5 %).
     * @return int TVA en centimes.
     */
    private function tvaEnCentimes(int $baseCts, int $taux100): int
    {
        $produit = $baseCts * $taux100; // centimes x centiemes de pour cent
        $signe = $produit < 0 ? -1 : 1;

        return $signe * intdiv(abs($produit) + 5000, 10000);
    }
}
