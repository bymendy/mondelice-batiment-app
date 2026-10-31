<?php

namespace App\Enums;

/**
 * Categorie d'un article de la Bibliotheque de prix (tache 3.3).
 *
 * Determine comment la marge se calcule et comment l'article se filtre
 * dans le catalogue -- pas de table a part, un enum PHP natif suffit
 * puisque la liste est fermee et ne varie pas par tenant.
 */
enum CategorieArticle: string
{
    case Materiaux = 'materiaux';
    case MainOeuvre = 'main_oeuvre';
    case SousTraitance = 'sous_traitance';
    case Forfait = 'forfait';

    /**
     * Libelle lisible pour l'UI.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Materiaux => 'Matériaux',
            self::MainOeuvre => 'Main d\'œuvre',
            self::SousTraitance => 'Sous-traitance',
            self::Forfait => 'Forfait',
        };
    }
}
