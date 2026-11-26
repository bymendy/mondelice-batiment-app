<?php

namespace App\Services\Import;

use RuntimeException;
use ZipArchive;

/**
 * Écrit un tableau de lignes dans un fichier CSV ou Excel (.xlsx), sans
 * bibliothèque externe (lot F3b). Pendant du LecteurTableur.
 *
 * Une cellule est un texte (chaîne), un nombre (entier ou flottant) ou vide
 * (null). Dans le fichier Excel, les textes restent des textes : une
 * référence comme « 0012 » ou « 1-2 » n'est pas transformée par le tableur.
 */
final class EcrivainTableur
{
    /**
     * CSV pour un tableur français : UTF-8 avec marque d'ordre, point-virgule,
     * virgule décimale.
     *
     * @param array<int, array<int, string|int|float|null>> $lignes
     * @return string
     */
    public function csv(array $lignes): string
    {
        $sortie = "\xEF\xBB\xBF";
        foreach ($lignes as $ligne) {
            $cellules = [];
            foreach ($ligne as $valeur) {
                if ($valeur === null) {
                    $cellules[] = '';
                } elseif (is_string($valeur)) {
                    $cellules[] = preg_match('/[";\r\n]/', $valeur) === 1 ? '"' . str_replace('"', '""', $valeur) . '"' : $valeur;
                } else {
                    $cellules[] = str_replace('.', ',', self::nombre($valeur));
                }
            }
            $sortie .= implode(';', $cellules) . "\r\n";
        }

        return $sortie;
    }

    /**
     * Fichier Excel d'une seule feuille. La première ligne (l'en-tête) est figée.
     *
     * @param array<int, array<int, string|int|float|null>> $lignes
     * @param string $feuille Nom de la feuille.
     * @return string Contenu binaire du fichier.
     */
    public function xlsx(array $lignes, string $feuille = 'Fournitures'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetData>';
        foreach (array_values($lignes) as $i => $ligne) {
            $xml .= '<row r="' . ($i + 1) . '">';
            foreach (array_values($ligne) as $j => $valeur) {
                if ($valeur === null || $valeur === '') {
                    continue;
                }
                $cellule = self::colonne($j) . ($i + 1);
                $xml .= is_string($valeur)
                    ? '<c r="' . $cellule . '" t="inlineStr"><is><t xml:space="preserve">' . self::texte($valeur) . '</t></is></c>'
                    : '<c r="' . $cellule . '"><v>' . self::nombre($valeur) . '</v></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($chemin === false) {
            throw new RuntimeException('Impossible de préparer le fichier Excel.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($chemin, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de préparer le fichier Excel.');
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . self::texte(mb_substr($feuille, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>');
            $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
                . '</styleSheet>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();

            $contenu = file_get_contents($chemin);
        } finally {
            @unlink($chemin);
        }

        if ($contenu === false) {
            throw new RuntimeException('Impossible de préparer le fichier Excel.');
        }

        return $contenu;
    }

    /**
     * Nombre sans zéros inutiles, avec un point décimal : 105.5, 12, 0.3333.
     *
     * @param int|float $valeur
     * @return string
     */
    private static function nombre(int|float $valeur): string
    {
        if (is_int($valeur)) {
            return (string) $valeur;
        }
        $texte = rtrim(rtrim(number_format($valeur, 4, '.', ''), '0'), '.');

        return $texte === '' || $texte === '-0' ? '0' : $texte;
    }

    /**
     * Texte sûr pour le XML (caractères de contrôle retirés).
     *
     * @param string $valeur
     * @return string
     */
    private static function texte(string $valeur): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $valeur) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Lettres de la colonne : 0 -> A, 25 -> Z, 26 -> AA.
     *
     * @param int $index
     * @return string
     */
    private static function colonne(int $index): string
    {
        $lettres = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $lettres = chr(65 + ($n - 1) % 26) . $lettres;
        }

        return $lettres;
    }
}
