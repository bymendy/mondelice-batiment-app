<?php

namespace App\Enums;

/**
 * Unite de mesure d'un article de la Bibliotheque de prix (tache 3.3).
 * Modifiable par article (document de reference : m2, ml, m3, u, h, forfait).
 */
enum UniteArticle: string
{
    case M2 = 'm2';
    case Ml = 'ml';
    case M3 = 'm3';
    case U = 'u';
    case H = 'h';
    case Forfait = 'forfait';

    /**
     * Libelle affiche dans l'UI (ex. "m2" -> "m²").
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::M2 => 'm²',
            self::Ml => 'ml',
            self::M3 => 'm³',
            self::U => 'u',
            self::H => 'h',
            self::Forfait => 'forfait',
        };
    }
}
