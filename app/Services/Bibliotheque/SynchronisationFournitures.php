<?php

namespace App\Services\Bibliotheque;

use App\Models\ArticleComposant;
use App\Models\Fourniture;

/**
 * Lien vivant entre la Base de fournitures et les articles (lot F1).
 *
 * Une ligne d'article `fourniture_base` garde une copie de la désignation,
 * de l'unité et des prix de sa fourniture. Quand la fourniture change, toutes
 * ces copies sont remises à jour dans la même transaction : les articles
 * suivent, sans qu'aucun calcul de prix existant ne soit modifié.
 *
 * Les lignes de devis ne sont jamais touchées : un devis déjà écrit garde
 * son prix (prix figé).
 */
final class SynchronisationFournitures
{
    /**
     * Valeurs qu'une ligne d'article reprend de sa fourniture.
     *
     * @param int $fournitureId Fourniture déjà validée.
     * @return array{fourniture_id: int, designation: string, unite: string, prix_achat_ht: string|null, prix_vente_ht: string}
     */
    public static function valeursLigne(int $fournitureId): array
    {
        $fourniture = Fourniture::query()->findOrFail($fournitureId);

        return ['fourniture_id' => (int) $fourniture->getKey()] + self::copie($fourniture);
    }

    /**
     * Remet à jour les lignes d'articles qui citent cette fourniture.
     *
     * @param Fourniture $fourniture
     * @return int Nombre d'articles concernés.
     */
    public static function propager(Fourniture $fourniture): int
    {
        $lignes = ArticleComposant::query()->where('fourniture_id', $fourniture->getKey());
        $articles = (clone $lignes)->distinct()->count('article_id');

        if ($articles > 0) {
            $lignes->update(self::copie($fourniture));
        }

        return $articles;
    }

    /**
     * Nombre d'articles qui utilisent cette fourniture.
     *
     * @param Fourniture $fourniture
     * @return int
     */
    public static function articlesUtilisant(Fourniture $fourniture): int
    {
        $id = $fourniture->getKey();

        return ArticleComposant::query()
            ->where(fn ($q) => $q->where('fourniture_id', $id)->orWhere('fourniture_eco_id', $id)->orWhere('fourniture_haut_id', $id))
            ->distinct()->count('article_id');
    }

    /**
     * @param Fourniture $fourniture
     * @return array{designation: string, unite: string, prix_achat_ht: string|null, prix_vente_ht: string}
     */
    private static function copie(Fourniture $fourniture): array
    {
        return [
            'designation' => $fourniture->designation,
            'unite' => $fourniture->unite,
            'prix_achat_ht' => $fourniture->prix_achat_ht,
            'prix_vente_ht' => $fourniture->prix_vente_ht,
        ];
    }
}
