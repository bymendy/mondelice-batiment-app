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
     * Quand le séparateur décimal de la colonne est connu (lot F3c, voir
     * convention()), l'autre signe sépare les milliers : avec une virgule
     * décimale, « 1.234 » vaut 1 234 ; avec un point décimal, « 1,234 » vaut
     * 1 234. Sans cette information, un signe seul est pris pour décimal.
     *
     * @param string|null $valeur
     * @param string|null $decimale Séparateur décimal de la colonne (`,` ou `.`), ou null s'il est inconnu.
     * @param bool $pointSur Un point seul est toujours décimal (nombre d'une cellule Excel).
     * @return float|null
     */
    public static function nombre(?string $valeur, ?string $decimale = null, bool $pointSur = false): ?float
    {
        if ($valeur === null) {
            return null;
        }
        $texte = self::epurer($valeur);
        if ($texte === '') {
            return null;
        }

        if ($decimale !== null && ! ($pointSur && ! str_contains($texte, ','))) {
            $texte = str_replace($decimale === ',' ? '.' : ',', '', $texte);
            if ($decimale === ',') {
                $texte = str_replace(',', '.', $texte);
            }

            return preg_match('/^-?\d+(\.\d+)?$/', $texte) === 1 ? round((float) $texte, 4) : null;
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
     * Séparateur décimal d'une colonne de nombres, déduit de toutes ses
     * valeurs (lot F3c). Une seule valeur sans équivoque suffit : « 12,50 »
     * (deux décimales), « 0,125 » (un zéro devant), « 1.234,56 » (les deux
     * signes). « 1,234 » seul est équivoque : 1,234 ou 1 234.
     *
     * @param array<int, string|null> $valeurs Cellules de la colonne.
     * @param bool $pointSur Un point seul est toujours décimal (fichier Excel).
     * @return array{decimale: string|null, etat: string, exemple: string|null}
     *         `etat` : `sur` (séparateur trouvé, ou aucune valeur équivoque),
     *         `ambigu` (que des valeurs équivoques) ou `melange` (les deux
     *         séparateurs décimaux dans la même colonne).
     */
    public static function convention(array $valeurs, bool $pointSur = false): array
    {
        $votes = [',' => 0, '.' => 0];
        $exemple = null;

        foreach ($valeurs as $valeur) {
            $texte = ltrim(self::epurer((string) $valeur), '-');
            if ($texte === '' || preg_match('/^[\d.,]+$/', $texte) !== 1) {
                continue;
            }

            $virgule = strrpos($texte, ',');
            $point = strrpos($texte, '.');
            if ($virgule !== false && $point !== false) {
                $votes[$virgule > $point ? ',' : '.']++;

                continue;
            }

            $signe = $virgule !== false ? ',' : ($point !== false ? '.' : null);
            if ($signe === null || ($signe === '.' && $pointSur)) {
                continue;
            }

            $morceaux = explode($signe, $texte);
            if (count($morceaux) > 2) {
                // « 1,234,567 » : le signe sépare les milliers, l'autre est décimal.
                $votes[$signe === ',' ? '.' : ',']++;
            } elseif (self::equivoque($morceaux[0], $morceaux[1])) {
                $exemple ??= trim((string) $valeur);
            } else {
                $votes[$signe]++;
            }
        }

        if ($votes[','] > 0 && $votes['.'] > 0) {
            return ['decimale' => null, 'etat' => 'melange', 'exemple' => $exemple];
        }
        if ($votes[','] > 0 || $votes['.'] > 0) {
            return ['decimale' => $votes[','] > 0 ? ',' : '.', 'etat' => 'sur', 'exemple' => null];
        }

        return ['decimale' => null, 'etat' => $exemple !== null ? 'ambigu' : 'sur', 'exemple' => $exemple];
    }

    /**
     * La valeur est-elle équivoque (« 1,234 » : décimale ou milliers) ?
     *
     * @param string|null $valeur
     * @param bool $pointSur Un point seul est toujours décimal (fichier Excel).
     * @return bool
     */
    public static function estEquivoque(?string $valeur, bool $pointSur = false): bool
    {
        $texte = ltrim(self::epurer((string) $valeur), '-');
        foreach ([',', '.'] as $signe) {
            if ($signe === '.' && $pointSur) {
                continue;
            }
            if (preg_match('/^(\d+)' . preg_quote($signe, '/') . '(\d+)$/', $texte, $m) === 1) {
                return self::equivoque($m[1], $m[2]);
            }
        }

        return false;
    }

    /**
     * Un à trois chiffres sans zéro devant, puis exactement trois chiffres.
     *
     * @param string $entier
     * @param string $suite
     * @return bool
     */
    private static function equivoque(string $entier, string $suite): bool
    {
        return strlen($suite) === 3 && strlen($entier) >= 1 && strlen($entier) <= 3 && $entier[0] !== '0';
    }

    /**
     * Retire les espaces, la monnaie et les unités d'un nombre.
     *
     * @param string $valeur
     * @return string
     */
    private static function epurer(string $valeur): string
    {
        return preg_replace('/[\s\x{00A0}\x{202F}€%]|EUR|HT/u', '', $valeur) ?? '';
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
