<?php

namespace App\Services\Bibliotheque;

use App\Models\Parametre;
use Illuminate\Validation\ValidationException;

/**
 * Prix de vente d'une fourniture : saisi, ou calcule depuis le prix d'achat
 * (Bibliotheque de prix, lot 8).
 *
 * Quand on ne donne que le prix d'achat, le prix de vente se calcule avec la
 * marge par defaut des fournitures de l'entreprise (reglage
 * `marge_fournitures_defaut`) :
 *
 *     prix de vente = prix d'achat / (1 - marge / 100)
 *
 * La marge est un pourcentage du PRIX DE VENTE, comme le taux de marge affiche
 * et les seuils de la Direction. Exemple : achat 5,00 et marge 50 % donnent
 * 10,00 (coefficient 2,00). Le resultat est arrondi a 4 decimales et copie dans
 * la ligne : il reste modifiable ligne par ligne, et un changement ulterieur de
 * la marge par defaut ne le modifie pas.
 */
final class PrixVenteFourniture
{
    /**
     * Prix de vente d'une fourniture.
     *
     * @param mixed $vente Prix de vente saisi, ou null.
     * @param mixed $achat Prix d'achat saisi, ou null.
     * @param string $champ Champ de l'erreur (ex. `composants.2.prix_vente_ht` pour un lot de lignes).
     * @return float|int|string Le prix de vente saisi tel quel, sinon le prix calcule.
     *
     * @throws ValidationException 422 si on ne peut ni lire ni calculer le prix de vente.
     */
    public function resoudre(mixed $vente, mixed $achat, string $champ = 'prix_vente_ht'): mixed
    {
        if ($vente !== null && $vente !== '') {
            return $vente;
        }

        if ($achat === null || $achat === '') {
            throw ValidationException::withMessages([
                $champ => 'Indiquez le prix de vente (ou le prix d\'achat, pour le calculer avec la marge par défaut).',
            ]);
        }

        $marge = Parametre::lire('marge_fournitures_defaut');

        if ($marge === null) {
            throw ValidationException::withMessages([
                $champ => 'Indiquez le prix de vente, ou fixez la marge par défaut des fournitures dans les réglages de l\'entreprise.',
            ]);
        }

        return self::calculer((float) $achat, (float) $marge);
    }

    /**
     * @param float $achat Prix d'achat unitaire.
     * @param float $margePct Marge en % du prix de vente (0 a moins de 100).
     * @return float Prix de vente unitaire, a 4 decimales.
     */
    public static function calculer(float $achat, float $margePct): float
    {
        return round($achat / (1 - $margePct / 100), 4);
    }
}
