<?php

namespace App\Services\Devis;

use App\Services\Bibliotheque\Arrondi;

/**
 * Totaux, remises et marge d'un devis. C'est le seul endroit ou ils sont
 * calcules (source unique de verite, cadre Pilotage).
 *
 * Ordre de calcul :
 *  1. chaque ligne : quantite x prix unitaire, arrondi au centime (moitie a la
 *     hausse), puis remise de la ligne (remise_pct) ;
 *  2. remises par lot, dans leur ordre, sur le sous-total du lot ;
 *  3. remises globales, dans leur ordre, sur le total restant ;
 *  4. TVA calculee sur la base de chaque taux, puis arrondie.
 *
 * Une remise par lot ou globale est repartie entre les taux de TVA au prorata
 * des bases HT ; le taux dont la base est la plus forte prend le reste, pour
 * que la somme des parts soit exactement la remise.
 *
 * Une remise ne depasse jamais le montant sur lequel elle porte : au-dela,
 * elle est plafonnee et signalee (« plafonnee »).
 *
 * Marge : total HT apres remises - prix de revient. Si une ligne n'a pas de
 * prix de revient, la marge est dite incomplete : aucun montant ni taux n'est
 * donne (pas de marge trompeuse).
 *
 * Ce service ne lit pas la base : il recoit les lignes et les remises.
 */
class CalculTotauxDevis
{
    /**
     * @param iterable<\App\Models\LigneDevis> $lignes Lignes du devis.
     * @param iterable<\App\Models\DeductionDevis> $deductions Remises par lot et globales, dans leur ordre.
     * @return array<string, mixed> total_ht, total_tva, total_ttc, par_taux, par_lot,
     *                              total_ht_brut, total_remises, remises, marge.
     */
    public function calculer(iterable $lignes, iterable $deductions = []): array
    {
        $brutCts = 0;
        $remiseLignesCts = 0;
        $bases = [];   // [cle du lot][taux en centiemes de %] => base HT nette en centimes
        $lots = [];    // cle du lot => lot_id, cts, revient, complet
        $revientCts = 0;
        $revientComplet = true;

        foreach ($lignes as $ligne) {
            $brut = Arrondi::ligneEnCentimes($ligne->quantite, $ligne->prix_unitaire_ht);
            $pct100 = (int) round(((float) ($ligne->remise_pct ?? 0)) * 100);
            $remise = $this->part($brut, $pct100, 10000);
            $net = $brut - $remise;
            $taux100 = (int) round(((float) $ligne->taux_tva) * 100);

            $brutCts += $brut;
            $remiseLignesCts += $remise;

            $cle = $ligne->lot_id === null ? 'sans_lot' : (string) $ligne->lot_id;
            $lots[$cle] ??= [
                'lot_id' => $ligne->lot_id === null ? null : (int) $ligne->lot_id,
                'cts' => 0,
                'revient' => 0,
                'complet' => true,
            ];
            $lots[$cle]['cts'] += $net;
            $bases[$cle][$taux100] = ($bases[$cle][$taux100] ?? 0) + $net;

            if ($ligne->prix_revient_unitaire === null) {
                $revientComplet = false;
                $lots[$cle]['complet'] = false;
            } else {
                $revient = Arrondi::ligneEnCentimes($ligne->quantite, $ligne->prix_revient_unitaire);
                $revientCts += $revient;
                $lots[$cle]['revient'] += $revient;
            }
        }

        // Remises par lot, puis remises globales.
        $detail = [];
        $remiseLotsCts = 0;
        $globales = [];

        foreach ($deductions as $deduction) {
            if ($deduction->lot_id === null) {
                $globales[] = $deduction;

                continue;
            }

            $cle = (string) $deduction->lot_id;
            $base = $lots[$cle]['cts'] ?? 0;
            [$montant, $plafonnee] = $this->montantRemise($deduction, $base);

            foreach ($this->repartir($bases[$cle] ?? [], $montant) as $taux100 => $partRemise) {
                $bases[$cle][$taux100] -= $partRemise;
            }
            if (isset($lots[$cle])) {
                $lots[$cle]['cts'] -= $montant;
            }
            $remiseLotsCts += $montant;
            $detail[] = $this->ligneDeDetail($deduction, $base, $montant, $plafonnee);
        }

        $parTauxCts = [];
        foreach ($bases as $basesDuLot) {
            foreach ($basesDuLot as $taux100 => $base) {
                $parTauxCts[$taux100] = ($parTauxCts[$taux100] ?? 0) + $base;
            }
        }

        $remiseGlobaleCts = 0;
        foreach ($globales as $deduction) {
            $base = array_sum($parTauxCts);
            [$montant, $plafonnee] = $this->montantRemise($deduction, $base);

            foreach ($this->repartir($parTauxCts, $montant) as $taux100 => $partRemise) {
                $parTauxCts[$taux100] -= $partRemise;
            }
            $remiseGlobaleCts += $montant;
            $detail[] = $this->ligneDeDetail($deduction, $base, $montant, $plafonnee);
        }

        // TVA par taux, sur les bases apres remises.
        krsort($parTauxCts);
        $htCts = 0;
        $tvaCts = 0;
        $parTaux = [];
        foreach ($parTauxCts as $taux100 => $base) {
            $tva = $this->tvaEnCentimes($base, $taux100);
            $htCts += $base;
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
            'total_ht_brut' => Arrondi::euros($brutCts),
            'total_remises' => Arrondi::euros($brutCts - $htCts),
            'remises' => [
                'lignes' => Arrondi::euros($remiseLignesCts),
                'lots' => Arrondi::euros($remiseLotsCts),
                'globale' => Arrondi::euros($remiseGlobaleCts),
                'detail' => $detail,
            ],
            'marge' => array_merge(
                $this->marge($htCts, $revientCts, $revientComplet),
                [
                    'par_lot' => array_values(array_map(
                        fn (array $lot): array => array_merge(
                            ['lot_id' => $lot['lot_id'], 'total_ht' => Arrondi::euros($lot['cts'])],
                            $this->marge($lot['cts'], $lot['revient'], $lot['complet'])
                        ),
                        $lots
                    )),
                ]
            ),
        ];
    }

    /**
     * Revient, marge, taux de marge (sur le prix de vente) et coefficient.
     *
     * @param int $htCts Total HT apres remises, en centimes.
     * @param int $revientCts Prix de revient connu, en centimes.
     * @param bool $complet Faux si une ligne au moins n'a pas de prix de revient.
     * @return array<string, mixed>
     */
    private function marge(int $htCts, int $revientCts, bool $complet): array
    {
        $margeCts = $htCts - $revientCts;

        return [
            'revient_ht' => Arrondi::euros($revientCts),
            'revient_complet' => $complet,
            'marge_ht' => $complet ? Arrondi::euros($margeCts) : null,
            'taux_marge_pct' => $complet && $htCts > 0 ? round($margeCts / $htCts * 100, 2) : null,
            'coefficient' => $complet && $revientCts > 0 ? round($htCts / $revientCts, 2) : null,
        ];
    }

    /**
     * Montant d'une remise en centimes, plafonne a sa base.
     *
     * @param \App\Models\DeductionDevis $deduction
     * @param int $baseCts Montant sur lequel porte la remise, en centimes.
     * @return array{0: int, 1: bool} Montant retenu et indicateur « plafonnee ».
     */
    private function montantRemise(object $deduction, int $baseCts): array
    {
        $valeur100 = (int) round(((float) $deduction->valeur) * 100);
        $montant = $deduction->type === 'pourcentage'
            ? $this->part($baseCts, $valeur100, 10000)
            : $valeur100;

        $plafond = max($baseCts, 0);
        $retenu = max(0, min($montant, $plafond));

        return [$retenu, $montant > $plafond];
    }

    /**
     * @param \App\Models\DeductionDevis $deduction
     * @param int $baseCts
     * @param int $montantCts
     * @param bool $plafonnee
     * @return array<string, mixed>
     */
    private function ligneDeDetail(object $deduction, int $baseCts, int $montantCts, bool $plafonnee): array
    {
        return [
            'id' => $deduction->id ?? null,
            'lot_id' => $deduction->lot_id === null ? null : (int) $deduction->lot_id,
            'libelle' => $deduction->libelle,
            'type' => $deduction->type,
            'valeur' => (float) $deduction->valeur,
            'base_ht' => Arrondi::euros($baseCts),
            'montant' => Arrondi::euros($montantCts),
            'plafonnee' => $plafonnee,
        ];
    }

    /**
     * Repartit un montant entre des bases, au prorata. La base la plus forte
     * prend le reste : la somme des parts vaut exactement le montant.
     *
     * @param array<int, int> $bases Bases en centimes, par taux.
     * @param int $montant Montant a repartir, en centimes.
     * @return array<int, int> Part de chaque taux, en centimes.
     */
    private function repartir(array $bases, int $montant): array
    {
        $total = array_sum($bases);
        if ($montant === 0 || $total <= 0) {
            return [];
        }

        $pivot = null;
        foreach ($bases as $taux100 => $base) {
            if ($pivot === null || abs($base) > abs($bases[$pivot])) {
                $pivot = $taux100;
            }
        }

        $parts = [];
        $distribue = 0;
        foreach ($bases as $taux100 => $base) {
            if ($taux100 === $pivot) {
                continue;
            }
            $parts[$taux100] = $this->part($montant, $base, $total);
            $distribue += $parts[$taux100];
        }
        $parts[$pivot] = $montant - $distribue;

        return $parts;
    }

    /**
     * Valeur x numerateur / denominateur, arrondie a l'entier le plus proche
     * (moitie a la hausse en valeur absolue).
     *
     * @param int $valeur
     * @param int $numerateur
     * @param int $denominateur Strictement positif.
     * @return int
     */
    private function part(int $valeur, int $numerateur, int $denominateur): int
    {
        $produit = $valeur * $numerateur;
        $signe = $produit < 0 ? -1 : 1;

        return $signe * intdiv(2 * abs($produit) + $denominateur, 2 * $denominateur);
    }

    /**
     * TVA d'une base, arrondie au centime (moitie a la hausse).
     *
     * @param int $baseCts Base HT en centimes.
     * @param int $taux100 Taux en centiemes de pour cent (2000 = 20 %).
     * @return int
     */
    private function tvaEnCentimes(int $baseCts, int $taux100): int
    {
        return $this->part($baseCts, $taux100, 10000);
    }
}
