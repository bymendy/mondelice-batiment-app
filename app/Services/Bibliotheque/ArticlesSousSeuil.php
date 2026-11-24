<?php

namespace App\Services\Bibliotheque;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;

/**
 * Indicateur « sous le seuil » d'un article à prix forcé (lot F2a).
 *
 * Le prix forcé est contrôlé quand on le pose. Mais un coût peut monter
 * ensuite (fourniture, taux horaire, frais généraux) et faire passer l'article
 * sous la marge minimum fixée par la Direction. On ne bloque pas ce
 * changement (une hausse de prix d'un fournisseur est un fait) : on le
 * signale. L'indicateur est calculé à chaque lecture, il couvre donc toutes
 * les causes.
 */
final class ArticlesSousSeuil
{
    /**
     * @param ArticlePrix $article Article avec son sous-détail.
     * @param ControleSeuilsPrixForce|null $controle
     * @return array{sous_seuil: bool, prix_minimum_ht: float|null}
     */
    public static function evaluer(ArticlePrix $article, ?ControleSeuilsPrixForce $controle = null): array
    {
        $rien = ['sous_seuil' => false, 'prix_minimum_ht' => null];
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

        return [
            'sous_seuil' => round((float) $article->prix_force_ht, 2) + 0.000001 < $minimum,
            'prix_minimum_ht' => $minimum,
        ];
    }

    /**
     * Identifiants des articles à prix forcé passés sous le seuil.
     *
     * @param array<int, int>|null $parmi Limite la recherche à ces articles.
     * @return array<int, int>
     */
    public static function ids(?array $parmi = null): array
    {
        return array_column(self::liste($parmi), 'id');
    }

    /**
     * Articles sous le seuil parmi ceux qui utilisent une fourniture.
     *
     * @param int $fournitureId
     * @return array<int, array{id: int, reference: string, designation: string, prix_force_ht: float, prix_minimum_ht: float}>
     */
    public static function utilisant(int $fournitureId): array
    {
        $articles = ArticleComposant::query()->where('fourniture_id', $fournitureId)->distinct()->pluck('article_id')->map(fn ($id) => (int) $id)->all();

        return $articles === [] ? [] : self::liste($articles);
    }

    /**
     * @param array<int, int>|null $parmi
     * @return array<int, array{id: int, reference: string, designation: string, prix_force_ht: float, prix_minimum_ht: float}>
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

        $liste = [];
        foreach ($articles as $article) {
            $etat = self::evaluer($article, $controle);
            if ($etat['sous_seuil']) {
                $liste[] = [
                    'id' => (int) $article->id,
                    'reference' => (string) $article->reference,
                    'designation' => (string) $article->designation,
                    'prix_force_ht' => (float) $article->prix_force_ht,
                    'prix_minimum_ht' => (float) $etat['prix_minimum_ht'],
                ];
            }
        }

        return $liste;
    }
}
