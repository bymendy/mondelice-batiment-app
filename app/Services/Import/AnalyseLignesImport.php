<?php

namespace App\Services\Import;

/**
 * Comparatif d'un import de fournitures (lot F3a) : pour chaque ligne du
 * fichier, dit ce que l'import ferait, sans rien écrire.
 *
 * Règles (décisions du 25 novembre 2026) :
 *  - le rapprochement se fait par la référence, chez le fournisseur de
 *    l'import ; une ligne sans référence est une erreur (jamais de
 *    rapprochement par le libellé) ;
 *  - mode « prix » : seuls les prix changent, une référence inconnue est
 *    ignorée ; mode « complet » : une référence inconnue est créée, la
 *    désignation et l'unité se mettent à jour ;
 *  - un écart de prix au-delà du seuil est « à valider » : la ligne n'est
 *    pas retenue d'office ;
 *  - une cellule vide ne remplace jamais une valeur existante.
 *
 * Classe sans accès à la base : elle reçoit les fournitures existantes.
 */
final class AnalyseLignesImport
{
    public const CHAMPS = ['reference', 'designation', 'unite', 'prix_tarif_ht', 'prix_achat_ht', 'remise_pct', 'prix_vente_ht'];

    /**
     * @param array<int, array<int, string>> $lignes Lignes de données (sans l'en-tête).
     * @param array<string, int|null> $correspondance Champ -> index de colonne.
     * @param string $mode `prix` ou `complet`.
     * @param array<string, array<string, mixed>> $existantes Fournitures du fournisseur, par référence
     *        (id, designation, unite, prix_tarif_ht, prix_achat_ht, remise_pct, prix_vente_ht, prix_vente_saisi).
     * @param float $seuilEcartPct Écart de prix au-delà duquel la ligne est à valider.
     * @param string $uniteDefaut Unité d'une fourniture créée quand le fichier n'en donne pas.
     * @param int $premiereLigne Numéro, dans le fichier, de la première ligne de données.
     * @return array<int, array<string, mixed>> Une entrée par ligne.
     */
    public function analyser(
        array $lignes,
        array $correspondance,
        string $mode,
        array $existantes,
        float $seuilEcartPct,
        string $uniteDefaut = 'u',
        int $premiereLigne = 2
    ): array {
        $champPrix = ($correspondance['prix_tarif_ht'] ?? null) !== null ? 'prix_tarif_ht' : 'prix_achat_ht';
        $vues = [];
        $resultat = [];

        foreach ($lignes as $rang => $cellules) {
            $numero = $premiereLigne + $rang;
            $lu = [];
            foreach (self::CHAMPS as $champ) {
                $colonne = $correspondance[$champ] ?? null;
                $lu[$champ] = $colonne === null ? null : trim((string) ($cellules[$colonne] ?? ''));
            }

            $erreurs = [];
            $reference = (string) ($lu['reference'] ?? '');
            if ($reference === '') {
                $erreurs[] = 'Référence manquante.';
            } elseif (mb_strlen($reference) > 80) {
                $erreurs[] = 'Référence trop longue (80 caractères au plus).';
            } elseif (isset($vues[$reference])) {
                $erreurs[] = sprintf('Référence en double dans le fichier (déjà à la ligne %d).', $vues[$reference]);
            } else {
                $vues[$reference] = $numero;
            }

            $prix = NormalisationImport::nombre($lu[$champPrix]);
            if ($prix === null) {
                $erreurs[] = ($lu[$champPrix] ?? '') === '' ? 'Prix manquant.' : sprintf('Prix illisible : « %s ».', $lu[$champPrix]);
            } elseif ($prix < 0) {
                $erreurs[] = 'Prix négatif.';
            }

            $remise = $this->facultatif($lu['remise_pct'], 'Remise', 0, 100, $erreurs);
            $vente = $this->facultatif($lu['prix_vente_ht'], 'Prix de vente', 0, null, $erreurs);

            $designation = ($lu['designation'] ?? '') !== '' ? (string) $lu['designation'] : null;
            if ($designation !== null && mb_strlen($designation) > 255) {
                $erreurs[] = 'Désignation trop longue (255 caractères au plus).';
            }
            $unite = ($lu['unite'] ?? '') !== '' ? (string) $lu['unite'] : null;
            if ($unite !== null && mb_strlen($unite) > 20) {
                $erreurs[] = 'Unité trop longue (20 caractères au plus).';
            }

            $ligne = [
                'numero_ligne' => $numero,
                'action' => 'erreur',
                'fourniture_id' => null,
                'reference' => $reference !== '' ? mb_substr($reference, 0, 255) : null,
                'designation' => $designation !== null ? mb_substr($designation, 0, 500) : null,
                'unite' => $unite !== null ? mb_substr($unite, 0, 50) : null,
                'prix_tarif_ht' => $champPrix === 'prix_tarif_ht' ? $prix : null,
                'prix_achat_ht' => $champPrix === 'prix_achat_ht' ? $prix : null,
                'remise_pct' => $remise,
                'prix_vente_ht' => $vente,
                'anciennes' => null,
                'modifications' => null,
                'ecart_pct' => null,
                'a_valider' => false,
                'retenue' => false,
                'erreurs' => null,
            ];

            $existante = $erreurs === [] ? ($existantes[$reference] ?? null) : null;

            if ($erreurs === [] && $existante === null && $mode === 'complet' && $designation === null) {
                $erreurs[] = 'Désignation manquante : elle est nécessaire pour créer la fourniture.';
            }

            if ($erreurs !== []) {
                $ligne['erreurs'] = $erreurs;
                $resultat[] = $ligne;

                continue;
            }

            if ($existante === null) {
                if ($mode === 'prix') {
                    $ligne['action'] = 'ignorer';
                } else {
                    $ligne['action'] = 'creer';
                    $ligne['unite'] = $unite ?? $uniteDefaut;
                    $ligne['retenue'] = true;
                }
                $resultat[] = $ligne;

                continue;
            }

            // Fourniture existante : on compare ce que le fichier apporte.
            $ligne['fourniture_id'] = $existante['id'];
            $ligne['anciennes'] = [
                'designation' => $existante['designation'],
                'unite' => $existante['unite'],
                'prix_tarif_ht' => $this->flottant($existante['prix_tarif_ht']),
                'prix_achat_ht' => $this->flottant($existante['prix_achat_ht']),
                'remise_pct' => $this->flottant($existante['remise_pct']),
                'prix_vente_ht' => $this->flottant($existante['prix_vente_ht']),
            ];

            $modifications = [];
            $ancienPrix = $ligne['anciennes'][$champPrix];
            // Un prix d'achat importé sur une fourniture à prix tarif remplace le tarif : c'est un changement.
            $changeDeBase = $champPrix === 'prix_achat_ht' && $ligne['anciennes']['prix_tarif_ht'] !== null;
            if ($ancienPrix === null || $changeDeBase || abs($prix - $ancienPrix) >= 0.00005) {
                $modifications[] = $champPrix;
                if ($ancienPrix !== null && $ancienPrix > 0) {
                    $ligne['ecart_pct'] = round(($prix - $ancienPrix) / $ancienPrix * 100, 2);
                }
            }
            if ($remise !== null && ($ligne['anciennes']['remise_pct'] === null || abs($remise - $ligne['anciennes']['remise_pct']) >= 0.005)) {
                $modifications[] = 'remise_pct';
            }
            if ($vente !== null && abs($vente - (float) $ligne['anciennes']['prix_vente_ht']) >= 0.00005) {
                $modifications[] = 'prix_vente_ht';
            }
            if ($mode === 'complet') {
                if ($designation !== null && $designation !== $existante['designation']) {
                    $modifications[] = 'designation';
                }
                if ($unite !== null && $unite !== $existante['unite']) {
                    $modifications[] = 'unite';
                }
            }

            if ($modifications === []) {
                $ligne['action'] = 'inchange';
            } else {
                $ligne['action'] = 'modifier';
                $ligne['modifications'] = $modifications;
                $ligne['a_valider'] = $ligne['ecart_pct'] !== null && abs($ligne['ecart_pct']) > $seuilEcartPct;
                $ligne['retenue'] = ! $ligne['a_valider'];
            }
            $resultat[] = $ligne;
        }

        return $resultat;
    }

    /**
     * Compte les lignes par action.
     *
     * @param array<int, array<string, mixed>> $lignes
     * @return array{a_creer: int, a_modifier: int, inchangees: int, ignorees: int, erreurs: int, a_valider: int, retenues: int}
     */
    public function resumer(array $lignes): array
    {
        $resume = ['a_creer' => 0, 'a_modifier' => 0, 'inchangees' => 0, 'ignorees' => 0, 'erreurs' => 0, 'a_valider' => 0, 'retenues' => 0];
        $cles = ['creer' => 'a_creer', 'modifier' => 'a_modifier', 'inchange' => 'inchangees', 'ignorer' => 'ignorees', 'erreur' => 'erreurs'];

        foreach ($lignes as $ligne) {
            $resume[$cles[$ligne['action']]]++;
            if ($ligne['a_valider']) {
                $resume['a_valider']++;
            }
            if ($ligne['retenue']) {
                $resume['retenues']++;
            }
        }

        return $resume;
    }

    /**
     * Nombre facultatif : une cellule vide ou une colonne absente donne null.
     *
     * @param string|null $texte
     * @param string $nom
     * @param float $min
     * @param float|null $max
     * @param array<int, string> $erreurs
     * @return float|null
     */
    private function facultatif(?string $texte, string $nom, float $min, ?float $max, array &$erreurs): ?float
    {
        if ($texte === null || $texte === '') {
            return null;
        }
        $nombre = NormalisationImport::nombre($texte);
        if ($nombre === null) {
            $erreurs[] = sprintf('%s illisible : « %s ».', $nom, $texte);

            return null;
        }
        if ($nombre < $min || ($max !== null && $nombre > $max)) {
            $erreurs[] = sprintf('%s hors limites : « %s ».', $nom, $texte);

            return null;
        }

        return $nombre;
    }

    /**
     * @param mixed $valeur
     * @return float|null
     */
    private function flottant(mixed $valeur): ?float
    {
        return $valeur === null ? null : (float) $valeur;
    }
}
