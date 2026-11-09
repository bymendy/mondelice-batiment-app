<?php

namespace App\Enums;

/**
 * Type d'une ligne du sous-detail d'un article (Bibliotheque de prix, lot 2).
 *
 * La main-d'oeuvre se calcule depuis les taux horaires de l'entreprise ; les
 * autres types portent leurs propres prix.
 */
enum TypeComposant: string
{
    case MainOeuvre = 'main_oeuvre';
    case FournitureBase = 'fourniture_base';
    case FournitureLibre = 'fourniture_libre';
    case Materiel = 'materiel';
    case Frais = 'frais';

    /**
     * Libelle lisible pour l'UI.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::MainOeuvre => 'Main d\'œuvre',
            self::FournitureBase => 'Fourniture de la base',
            self::FournitureLibre => 'Fourniture libre',
            self::Materiel => 'Matériel',
            self::Frais => 'Frais',
        };
    }
}
