<?php

namespace App\Services\Bibliotheque;

/**
 * Arrondi des montants d'une ligne au centime (Bibliotheque de prix, lot 8).
 *
 * Regle : le montant d'une ligne (quantite x prix unitaire) est arrondi au
 * centime, au demi-centime superieur, puis les lignes sont additionnees. Le
 * total est ainsi toujours la somme de ce que le client voit.
 *
 * Les prix unitaires gardent jusqu'a 4 decimales (une vis a 0,0207 EUR) : on
 * n'arrondit que le montant de la ligne. Le calcul se fait en nombres entiers
 * (quantite et prix en dix-milliemes) : pas de bruit de virgule flottante,
 * 1 x 1,005 donne toujours 1,01.
 */
final class Arrondi
{
    /**
     * Montant d'une ligne en centimes.
     *
     * @param mixed $quantite Quantite (jusqu'a 4 decimales) ; null vaut 0.
     * @param mixed $prix Prix unitaire en euros (jusqu'a 4 decimales) ; null vaut 0.
     * @return int Montant arrondi au centime, en centimes.
     */
    public static function ligneEnCentimes(mixed $quantite, mixed $prix): int
    {
        $q = (int) round((float) $quantite * 10000);
        $p = (int) round((float) $prix * 10000);
        $produit = $q * $p; // en cent-millionniemes d'euro

        if (is_float($produit)) {
            // Depassement d'entier (valeurs absurdes) : repli sur le flottant.
            return (int) round($produit / 1000000);
        }

        $signe = $produit < 0 ? -1 : 1;

        return $signe * intdiv(abs($produit) + 500000, 1000000);
    }

    /**
     * Montant en euros d'un nombre de centimes.
     *
     * @param int $centimes
     * @return float
     */
    public static function euros(int $centimes): float
    {
        return $centimes / 100.0;
    }

    /**
     * Montant d'une ligne en euros, arrondi au centime.
     *
     * @param mixed $quantite
     * @param mixed $prix
     * @return float
     */
    public static function ligne(mixed $quantite, mixed $prix): float
    {
        return self::euros(self::ligneEnCentimes($quantite, $prix));
    }
}
