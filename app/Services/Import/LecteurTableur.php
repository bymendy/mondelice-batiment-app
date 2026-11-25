<?php

namespace App\Services\Import;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Lecture d'un fichier CSV ou Excel (.xlsx) en lignes de textes (lot F3a).
 *
 * Aucune bibliothèque externe : le CSV se lit avec les fonctions de PHP, le
 * fichier Excel est une archive ZIP de fichiers XML lue en flux (ZipArchive
 * et XMLReader). Seule la première feuille est lue. Les formules ne sont pas
 * recalculées : on lit la dernière valeur enregistrée par le tableur.
 *
 * Le lecteur s'arrête dès que la limite de lignes est dépassée : il ne charge
 * jamais un fichier entier en mémoire au-delà de cette limite.
 */
final class LecteurTableur
{
    public const FORMATS = ['csv', 'xlsx'];

    /**
     * @param string $chemin Chemin du fichier sur le disque.
     * @param string $format `csv` ou `xlsx`.
     * @param int $maxLignes Nombre maximum de lignes lues (les lignes vides ne comptent pas).
     * @return array{lignes: array<int, array<int, string>>, tronque: bool} Lignes non vides, dans l'ordre.
     *
     * @throws RuntimeException Si le fichier est illisible.
     */
    public function lire(string $chemin, string $format, int $maxLignes): array
    {
        return match ($format) {
            'csv' => $this->lireCsv($chemin, $maxLignes),
            'xlsx' => $this->lireXlsx($chemin, $maxLignes),
            default => throw new RuntimeException('Format de fichier non pris en charge.'),
        };
    }

    /**
     * CSV : encodage UTF-8 ou Windows-1252 (tableurs français), séparateur
     * détecté parmi point-virgule, virgule, tabulation et barre verticale.
     *
     * @param string $chemin
     * @param int $maxLignes
     * @return array{lignes: array<int, array<int, string>>, tronque: bool}
     */
    private function lireCsv(string $chemin, int $maxLignes): array
    {
        $contenu = @file_get_contents($chemin);
        if ($contenu === false) {
            throw new RuntimeException('Le fichier est illisible.');
        }
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);
        }
        if (! mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }

        $separateur = $this->separateur($contenu);
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, $contenu);
        rewind($flux);

        $lignes = [];
        $tronque = false;
        while (($champs = fgetcsv($flux, null, $separateur, '"', '')) !== false) {
            $ligne = $this->nettoyer(array_map(static fn ($v): string => (string) $v, $champs));
            if ($ligne === []) {
                continue;
            }
            if (count($lignes) >= $maxLignes) {
                $tronque = true;
                break;
            }
            $lignes[] = $ligne;
        }
        fclose($flux);

        return ['lignes' => $lignes, 'tronque' => $tronque];
    }

    /**
     * @param string $contenu
     * @return string
     */
    private function separateur(string $contenu): string
    {
        $premiere = strtok($contenu, "\r\n") ?: '';
        $meilleur = ';';
        $max = 0;
        foreach ([';', ',', "\t", '|'] as $candidat) {
            $n = substr_count($premiere, $candidat);
            if ($n > $max) {
                $max = $n;
                $meilleur = $candidat;
            }
        }

        return $meilleur;
    }

    /**
     * @param string $chemin
     * @param int $maxLignes
     * @return array{lignes: array<int, array<int, string>>, tronque: bool}
     */
    private function lireXlsx(string $chemin, int $maxLignes): array
    {
        $zip = new ZipArchive();
        if ($zip->open($chemin, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Le fichier Excel est illisible.');
        }

        try {
            $feuille = $this->premiereFeuille($zip);
            if ($feuille === null) {
                throw new RuntimeException('Le fichier Excel ne contient aucune feuille.');
            }
            $textes = $this->textesPartages($zip);

            return $this->lireFeuille($zip, $feuille, $textes, $maxLignes);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param ZipArchive $zip
     * @return string|null Nom du fichier de la première feuille dans l'archive.
     */
    private function premiereFeuille(ZipArchive $zip): ?string
    {
        $feuilles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $nom, $m) === 1) {
                $feuilles[(int) $m[1]] = $nom;
            }
        }
        if ($feuilles === []) {
            return null;
        }
        ksort($feuilles);

        return reset($feuilles);
    }

    /**
     * Table des textes partagés (xl/sharedStrings.xml), dans l'ordre.
     *
     * @param ZipArchive $zip
     * @return array<int, string>
     */
    private function textesPartages(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $xml = $this->ouvrir($zip, 'xl/sharedStrings.xml');

        $textes = [];
        $courant = null;
        $dansPhonetique = false;
        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT) {
                if ($xml->localName === 'si') {
                    $courant = '';
                    if ($xml->isEmptyElement) {
                        $textes[] = '';
                        $courant = null;
                    }
                } elseif ($xml->localName === 'rPh') {
                    $dansPhonetique = ! $xml->isEmptyElement;
                } elseif ($xml->localName === 't' && $courant !== null && ! $dansPhonetique && ! $xml->isEmptyElement) {
                    $courant .= $xml->readString();
                }
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT) {
                if ($xml->localName === 'si' && $courant !== null) {
                    $textes[] = $courant;
                    $courant = null;
                } elseif ($xml->localName === 'rPh') {
                    $dansPhonetique = false;
                }
            }
        }
        $xml->close();

        return $textes;
    }

    /**
     * @param ZipArchive $zip
     * @param string $feuille
     * @param array<int, string> $textes
     * @param int $maxLignes
     * @return array{lignes: array<int, array<int, string>>, tronque: bool}
     */
    private function lireFeuille(ZipArchive $zip, string $feuille, array $textes, int $maxLignes): array
    {
        $xml = $this->ouvrir($zip, $feuille);

        $lignes = [];
        $tronque = false;
        $ligne = null;
        $colonne = 0;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'row') {
                $ligne = [];
                $colonne = 0;
                if ($xml->isEmptyElement) {
                    $ligne = null;
                }

                continue;
            }

            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'c' && $ligne !== null) {
                $reference = (string) $xml->getAttribute('r');
                $type = (string) $xml->getAttribute('t');
                if ($reference !== '') {
                    $colonne = $this->indexColonne($reference);
                }
                if ($colonne <= 500) {
                    $ligne[$colonne] = $xml->isEmptyElement ? '' : $this->valeurCellule($xml, $type, $textes);
                }
                $colonne++;

                continue;
            }

            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'row' && $ligne !== null) {
                $complete = [];
                if ($ligne !== []) {
                    $dernier = max(array_keys($ligne));
                    for ($i = 0; $i <= $dernier; $i++) {
                        $complete[] = $ligne[$i] ?? '';
                    }
                }
                $complete = $this->nettoyer($complete);
                $ligne = null;
                if ($complete === []) {
                    continue;
                }
                if (count($lignes) >= $maxLignes) {
                    $tronque = true;
                    break;
                }
                $lignes[] = $complete;
            }
        }
        $xml->close();

        return ['lignes' => $lignes, 'tronque' => $tronque];
    }

    /**
     * Valeur d'une cellule : lit le contenu de <c> (éléments <v> ou <is><t>).
     *
     * @param XMLReader $xml Positionné sur l'élément <c>.
     * @param string $type Attribut t de la cellule.
     * @param array<int, string> $textes
     * @return string
     */
    private function valeurCellule(XMLReader $xml, string $type, array $textes): string
    {
        $profondeur = $xml->depth;
        $valeur = '';
        $texteEnLigne = '';

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $profondeur) {
                break;
            }
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->isEmptyElement) {
                continue;
            }
            if ($xml->localName === 'v') {
                $valeur = $xml->readString();
            } elseif ($xml->localName === 't') {
                $texteEnLigne .= $xml->readString();
            }
        }

        return match ($type) {
            's' => $textes[(int) $valeur] ?? '',
            'inlineStr' => $texteEnLigne,
            'b' => $valeur === '1' ? 'VRAI' : 'FAUX',
            'e' => '',
            default => $valeur,
        };
    }

    /**
     * Index (à partir de 0) de la colonne d'une référence de cellule : A1 -> 0, AB12 -> 27.
     *
     * @param string $reference
     * @return int
     */
    private function indexColonne(string $reference): int
    {
        $index = 0;
        $longueur = strlen($reference);
        for ($i = 0; $i < $longueur; $i++) {
            $code = ord(strtoupper($reference[$i]));
            if ($code < 65 || $code > 90) {
                break;
            }
            $index = $index * 26 + ($code - 64);
        }

        return max(0, $index - 1);
    }

    /**
     * @param ZipArchive $zip
     * @param string $nom
     * @return XMLReader
     */
    private function ouvrir(ZipArchive $zip, string $nom): XMLReader
    {
        $contenu = $zip->getFromName($nom);
        if ($contenu === false) {
            throw new RuntimeException('Le fichier Excel est incomplet.');
        }
        $xml = new XMLReader();
        if (! $xml->XML($contenu, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Le fichier Excel est illisible.');
        }

        return $xml;
    }

    /**
     * Retire les espaces, puis les cellules vides en fin de ligne. Une ligne
     * entièrement vide donne un tableau vide.
     *
     * @param array<int, string> $ligne
     * @return array<int, string>
     */
    private function nettoyer(array $ligne): array
    {
        $ligne = array_map(static fn (string $v): string => trim(preg_replace('/\s+/u', ' ', $v) ?? $v), $ligne);
        while ($ligne !== [] && end($ligne) === '') {
            array_pop($ligne);
        }

        return $ligne;
    }
}
