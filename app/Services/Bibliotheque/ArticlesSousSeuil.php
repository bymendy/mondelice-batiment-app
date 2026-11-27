<?php

namespace App\Services\Bibliotheque;

use App\Exceptions\QualificationInconnueException;
use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\TauxHoraire;

/**
 * Indicateur « sous le seuil » d'un article à prix forcé (lot F2a).
 *
 * Le prix forcé est contrôlé quand on le pose. Mais un coût peut monter
 * ensuite (fourniture, taux horaire, frais généraux) et faire passer l'article
 * sous la marge minimum fixée par la Direction. On ne bloque pas ce
 * changement (une hausse de prix d'un fournisseur est un fait) : on le
 * signale. L'indicateur est calculé à chaque lecture, il couvre donc toutes
 * les causes.
 *
 * Lot F4b : le contrôle couvre les trois niveaux de prestation. Un article
 * est signalé dès qu'un de ses niveaux passe sous son minimum, même si le
 * niveau standard le respecte (`niveaux_sous_seuil`). Une fourniture utilisée
 * comme variante compte comme une fourniture standard.
 */
final class ArticlesSousSeuil
{
    /**
     * @param ArticlePrix $article Article avec son sous-détail.
     * @param ControleSeuilsPrixForce|null $controle
     * @param array<string, TauxHoraire>|null $tauxParCode Taux de l'entreprise (lus si absents).
     * @return array{sous_seuil: bool, prix_minimum_ht: float|null, niveaux_sous_seuil: array<int, string>}
     *         `sous_seuil` et `prix_minimum_ht` : niveau standard ; `niveaux_sous_seuil` : tous les niveaux concernés.
     */
    public static function evaluer(ArticlePrix $article, ?ControleSeuilsPrixForce $controle = null, ?array $tauxParCode = null): array
    {
        $rien = ['sous_seuil' => false, 'prix_minimum_ht' => null, 'niveaux_sous_seuil' => []];
        if ($article->prix_force_ht === null || ! tenant_id()) {
            return $rien;
        }

        $controle ??= ControleSeuilsPrixForce::pourEntrepriseCourante();
        if (! $controle->estActif()) {
            return $rien;
        }

        $minimum = $controle->prixMinimum($article->composants);
        if ($minimum === null) {
            return $rien;
        }

        $force = (float) $article->prix_force_ht;
        $standardSousSeuil = round($force, 2) + 0.000001 < $minimum;
        $niveaux = $standardSousSeuil ? [NiveauPrestation::STANDARD] : [];

        // Les autres niveaux ne se calculent que si l'article a des variantes.
        $ventes = [];
        foreach (array_keys(NiveauPrestation::COLONNES) as $niveau) {
            $auNiveau = NiveauPrestation::composantsAuNiveau($article->composants, $niveau);
            if ($auNiveau['remplacees'] === []) {
                continue;
            }
            $minimumNiveau = $controle->prixMinimum($auNiveau['composants']);
            if ($minimumNiveau === null) {
                continue;
            }

            try {
                $tauxParCode ??= TauxHoraire::indexesParCode();
                $calcul = new CalculPrixArticle();
                $frais = PrixVenteFourniture::fraisGenerauxPct();
                $ventes['standard'] ??= $calcul->calculer($article->composants, $tauxParCode, null, $frais)->prixVenteSousDetailHt;
                $vente = $calcul->calculer($auNiveau['composants'], $tauxParCode, null, $frais)->prixVenteSousDetailHt;
            } catch (QualificationInconnueException) {
                break;
            }

            if (round(NiveauPrestation::prixAuNiveau($force, $ventes['standard'], $vente), 2) + 0.000001 < $minimumNiveau) {
                $niveaux[] = $niveau;
            }
        }

        return [
            'sous_seuil' => $standardSousSeuil,
            'prix_minimum_ht' => $minimum,
            'niveaux_sous_seuil' => $niveaux,
        ];
    }

    /**
     * Identifiants des articles à prix forcé passés sous le seuil, à un niveau au moins.
     *
     * @param array<int, int>|null $parmi Limite la recherche à ces articles.
     * @return array<int, int>
     */
    public static function ids(?array $parmi = null): array
    {
        return array_column(self::liste($parmi), 'id');
    }

    /**
     * Articles sous le seuil parmi ceux qui utilisent une fourniture, comme
     * fourniture standard ou comme variante.
     *
     * @param int $fournitureId
     * @return array<int, array{id: int, reference: string, designation: string, prix_force_ht: float, prix_minimum_ht: float, niveaux_sous_seuil: array<int, string>}>
     */
    public static function utilisant(int $fournitureId): array
    {
        $articles = ArticleComposant::query()
            ->where(fn ($q) => $q->where('fourniture_id', $fournitureId)->orWhere('fourniture_eco_id', $fournitureId)->orWhere('fourniture_haut_id', $fournitureId))
            ->distinct()->pluck('article_id')->map(fn ($id) => (int) $id)->all();

        return $articles === [] ? [] : self::liste($articles);
    }

    /**
     * @param array<int, int>|null $parmi
     * @return array<int, array{id: int, reference: string, designation: string, prix_force_ht: float, prix_minimum_ht: float, niveaux_sous_seuil: array<int, string>}>
     */
    private static function liste(?array $parmi): array
    {
        $controle = ControleSeuilsPrixForce::pourEntrepriseCourante();
        if (! $controle->estActif()) {
            return [];
        }

        $articles = ArticlePrix::query()
            ->whereNotNull('prix_force_ht')
            ->when($parmi !== null, fn ($q) => $q->whereIn('id', $parmi))
            ->with('composants')
            ->orderBy('id')
            ->get();

        $taux = TauxHoraire::indexesParCode();
        $liste = [];
        foreach ($articles as $article) {
            $etat = self::evaluer($article, $controle, $taux);
            if ($etat['niveaux_sous_seuil'] !== []) {
                $liste[] = [
                    'id' => (int) $article->id,
                    'reference' => (string) $article->reference,
                    'designation' => (string) $article->designation,
                    'prix_force_ht' => (float) $article->prix_force_ht,
                    'prix_minimum_ht' => (float) $etat['prix_minimum_ht'],
                    'niveaux_sous_seuil' => $etat['niveaux_sous_seuil'],
                ];
            }
        }

        return $liste;
    }
}
