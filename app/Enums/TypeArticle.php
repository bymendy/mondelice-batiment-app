<?php

namespace App\Enums;

/**
 * Type d'un article de la Bibliotheque de prix (ancien enum CategorieArticle,
 * renomme au lot 3 : le mot « categorie » designe desormais l'arborescence
 * Domaine > Corps d'etat > Famille d'ouvrages, table categories_prix).
 *
 * Liste fermee, identique pour toutes les entreprises : un enum PHP natif
 * suffit, pas de table a part.
 */
enum TypeArticle: string
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
