<?php

namespace App\Services\Import;

/**
 * Nettoyage des valeurs lues dans un fichier d'import (lot F3a) : nombres
 * écrits à la française ou à l'anglaise, en-têtes de colonnes.
 */
final class NormalisationImport
{
    /**
     * Nombre lu dans une cellule : « 1 234,56 », « 12.5 », « 12,50 € »,
     * « 1.234,56 » ou « 1,234.56 ». Renvoie null si ce n'est pas un nombre.
     *
     * @param string|null $valeur
     * @return float|null
     */
    public static function nombre(?string $valeur): ?float
    {
        if ($valeur === null) {
            return null;
        }
        $texte = preg_replace('/[\s\x{00A0}\x{202F}€%]|EUR|HT/u', '', $valeur) ?? '';
        if ($texte === '') {
            return null;
        }

        $virgule = strrpos($texte, ',');
        $point = strrpos($texte, '.');
        if ($virgule !== false && $point !== false) {
            // Le dernier des deux est le séparateur décimal, l'autre sépare les milliers.
            if ($virgule > $point) {
                $texte = str_replace('.', '', $texte);
                $texte = str_replace(',', '.', $texte);
            } else {
                $texte = str_replace(',', '', $texte);
            }
        } elseif ($virgule !== false) {
            $texte = str_replace(',', '.', $texte);
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $texte) !== 1) {
            return null;
        }

        return round((float) $texte, 4);
    }

    /**
     * En-tête simplifié pour les comparaisons : minuscules, sans accents,
     * sans ponctuation.
     *
     * @param string $entete
     * @return string
     */
    public static function entete(string $entete): string
    {
        $texte = mb_strtolower(trim($entete));
        $texte = strtr($texte, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe',
        ]);
        $texte = preg_replace('/[^a-z0-9]+/', ' ', $texte) ?? $texte;

        return trim($texte);
    }

    /**
     * Propose une colonne pour chaque champ, d'après les en-têtes.
     *
     * @param array<int, string> $entetes
     * @return array<string, int|null> Champ -> index de colonne (ou null).
     */
    public static function proposer(array $entetes): array
    {
        // Du plus précis au plus vague : « prix achat » avant « prix ».
        $motsCles = [
            'reference' => ['reference', 'ref', 'code article', 'code', 'sku', 'article'],
            'prix_achat_ht' => ['prix achat', 'prix d achat', 'prix net', 'pa ht', 'achat', 'net'],
            'prix_vente_ht' => ['prix de vente', 'prix vente', 'prix conseille', 'pv conseille', 'pv ht', 'pv', 'vente'],
            'remise_pct' => ['remise', 'rem'],
            'designation' => ['designation', 'libelle', 'description', 'intitule', 'nom', 'produit'],
            'unite' => ['unite', 'unite de vente', 'uv', 'u'],
            'prix_tarif_ht' => ['prix public', 'prix tarif', 'tarif', 'prix brut', 'pu ht', 'prix ht', 'prix', 'pu'],
        ];

        $normalises = array_map(static fn (string $e): string => self::entete($e), $entetes);
        $pris = [];
        $proposition = [];

        foreach ($motsCles as $champ => $mots) {
            $proposition[$champ] = null;
            // D'abord une égalité exacte, puis un en-tête qui contient le mot.
            foreach ([true, false] as $exact) {
                foreach ($mots as $mot) {
                    foreach ($normalises as $index => $entete) {
                        if (isset($pris[$index]) || $entete === '') {
                            continue;
                        }
                        $trouve = $exact ? $entete === $mot : (strlen($mot) > 2 && preg_match('/\b' . preg_quote($mot, '/') . '\b/', $entete) === 1);
                        if ($trouve) {
                            $proposition[$champ] = $index;
                            $pris[$index] = true;

                            continue 4;
                        }
                    }
                }
            }
        }

        return $proposition;
    }
}
