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
 * et XMLReader). Une seule feuille est lue : la première par défaut, ou
 * celle qu'on choisit (lot F3c). Les formules ne sont pas recalculées : on
 * lit la dernière valeur enregistrée par le tableur.
 *
 * Chaque ligne garde son numéro réel dans le fichier (les lignes vides sont
 * sautées mais comptées), pour que les messages désignent la bonne ligne.
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
     * @param int $feuille Index de la feuille (0 pour la première ; sans effet pour un CSV).
     * @param int $aPartirDe Numéro de la première ligne à lire (1 pour le début du fichier).
     * @return array{lignes: array<int, array<int, string>>, numeros: array<int, int>, tronque: bool}
     *         Lignes non vides, dans l'ordre, et leur numéro dans le fichier.
     *
     * @throws RuntimeException Si le fichier est illisible ou si la feuille n'existe pas.
     */
    public function lire(string $chemin, string $format, int $maxLignes, int $feuille = 0, int $aPartirDe = 1): array
    {
        return match ($format) {
            'csv' => $this->lireCsv($chemin, $maxLignes, $aPartirDe),
            'xlsx' => $this->lireXlsx($chemin, $maxLignes, $feuille, $aPartirDe),
            default => throw new RuntimeException('Format de fichier non pris en charge.'),
        };
    }

    /**
     * Noms des feuilles d'un fichier Excel, dans l'ordre du classeur. Un CSV
     * n'a pas de feuille : la liste est vide.
     *
     * @param string $chemin
     * @param string $format
     * @return array<int, string>
     *
     * @throws RuntimeException Si le fichier est illisible.
     */
    public function feuilles(string $chemin, string $format): array
    {
        if ($format !== 'xlsx') {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($chemin, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Le fichier Excel est illisible.');
        }

        try {
            return array_column($this->listeFeuilles($zip), 'nom');
        } finally {
            $zip->close();
        }
    }

    /**
     * CSV : encodage UTF-8 ou Windows-1252 (tableurs français), séparateur
     * détecté parmi point-virgule, virgule, tabulation et barre verticale.
     *
     * @param string $chemin
     * @param int $maxLignes
     * @param int $aPartirDe
     * @return array{lignes: array<int, array<int, string>>, numeros: array<int, int>, tronque: bool}
     */
    private function lireCsv(string $chemin, int $maxLignes, int $aPartirDe): array
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
        $numeros = [];
        $tronque = false;
        $numero = 0;
        while (($champs = fgetcsv($flux, null, $separateur, '"', '')) !== false) {
            $numero++;
            if ($numero < $aPartirDe) {
                continue;
            }
            $ligne = $this->nettoyer(array_map(static fn ($v): string => (string) $v, $champs));
            if ($ligne === []) {
                continue;
            }
            if (count($lignes) >= $maxLignes) {
                $tronque = true;
                break;
            }
            $lignes[] = $ligne;
            $numeros[] = $numero;
        }
        fclose($flux);

        return ['lignes' => $lignes, 'numeros' => $numeros, 'tronque' => $tronque];
    }

    /**
     * Séparateur le plus fréquent dans les vingt premières lignes : un titre
     * au-dessus de l'en-tête ne trompe pas la détection (lot F3c).
     *
     * @param string $contenu
     * @return string
     */
    private function separateur(string $contenu): string
    {
        $debut = implode("\n", array_slice(preg_split('/\R/', substr($contenu, 0, 20000)) ?: [], 0, 20));
        $meilleur = ';';
        $max = 0;
        foreach ([';', ',', "\t", '|'] as $candidat) {
            $n = substr_count($debut, $candidat);
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
     * @param int $feuille
     * @param int $aPartirDe
     * @return array{lignes: array<int, array<int, string>>, numeros: array<int, int>, tronque: bool}
     */
    private function lireXlsx(string $chemin, int $maxLignes, int $feuille, int $aPartirDe): array
    {
        $zip = new ZipArchive();
        if ($zip->open($chemin, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Le fichier Excel est illisible.');
        }

        try {
            $feuilles = $this->listeFeuilles($zip);
            if ($feuilles === []) {
                throw new RuntimeException('Le fichier Excel ne contient aucune feuille.');
            }
            if (! isset($feuilles[$feuille])) {
                throw new RuntimeException('Cette feuille n\'existe pas dans le fichier Excel.');
            }
            $textes = $this->textesPartages($zip);

            return $this->lireFeuille($zip, $feuilles[$feuille]['fichier'], $textes, $maxLignes, $aPartirDe);
        } finally {
            $zip->close();
        }
    }

    /**
     * Feuilles du classeur, dans l'ordre où le tableur les affiche (nom et
     * fichier dans l'archive). Sans description du classeur, on se rabat sur
     * les fichiers sheetN.xml, nommés « Feuille N ».
     *
     * @param ZipArchive $zip
     * @return array<int, array{nom: string, fichier: string}>
     */
    private function listeFeuilles(ZipArchive $zip): array
    {
        $feuilles = [];

        if ($zip->locateName('xl/workbook.xml') !== false && $zip->locateName('xl/_rels/workbook.xml.rels') !== false) {
            $cibles = [];
            $liens = $this->ouvrir($zip, 'xl/_rels/workbook.xml.rels');
            while ($liens->read()) {
                if ($liens->nodeType === XMLReader::ELEMENT && $liens->localName === 'Relationship') {
                    $cible = (string) $liens->getAttribute('Target');
                    $cibles[(string) $liens->getAttribute('Id')] = str_starts_with($cible, '/') ? ltrim($cible, '/') : 'xl/' . $cible;
                }
            }
            $liens->close();

            $classeur = $this->ouvrir($zip, 'xl/workbook.xml');
            while ($classeur->read()) {
                if ($classeur->nodeType !== XMLReader::ELEMENT || $classeur->localName !== 'sheet') {
                    continue;
                }
                $id = (string) $classeur->getAttribute('r:id');
                if ($id === '') {
                    $id = (string) $classeur->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                }
                $fichier = $cibles[$id] ?? null;
                // Une feuille de graphique n'a pas de lignes : on ne garde que les feuilles de calcul.
                if ($fichier !== null && str_contains($fichier, 'worksheets/') && $zip->locateName($fichier) !== false) {
                    $feuilles[] = ['nom' => (string) $classeur->getAttribute('name'), 'fichier' => $fichier];
                }
            }
            $classeur->close();
        }

        if ($feuilles !== []) {
            return $feuilles;
        }

        $fichiers = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $nom, $m) === 1) {
                $fichiers[(int) $m[1]] = $nom;
            }
        }
        ksort($fichiers);
        foreach (array_values($fichiers) as $rang => $fichier) {
            $feuilles[] = ['nom' => 'Feuille ' . ($rang + 1), 'fichier' => $fichier];
        }

        return $feuilles;
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
     * @param int $aPartirDe
     * @return array{lignes: array<int, array<int, string>>, numeros: array<int, int>, tronque: bool}
     */
    private function lireFeuille(ZipArchive $zip, string $feuille, array $textes, int $maxLignes, int $aPartirDe): array
    {
        $xml = $this->ouvrir($zip, $feuille);

        $lignes = [];
        $numeros = [];
        $tronque = false;
        $ligne = null;
        $colonne = 0;
        $numero = 0;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'row') {
                // Le numéro est celui du tableur (attribut r) ; sans lui, on compte.
                $declare = (int) $xml->getAttribute('r');
                $numero = $declare > 0 ? $declare : $numero + 1;
                $ligne = [];
                $colonne = 0;
                if ($xml->isEmptyElement || $numero < $aPartirDe) {
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
                $numeros[] = $numero;
            }
        }
        $xml->close();

        return ['lignes' => $lignes, 'numeros' => $numeros, 'tronque' => $tronque];
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
