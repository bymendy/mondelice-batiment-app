<?php

namespace App\Enums;

/**
 * Type d'adresse d'un client : une seule adresse de facturation, plusieurs
 * adresses de chantier.
 */
enum TypeAdresse: string
{
    case Facturation = 'facturation';
    case Chantier = 'chantier';

    /**
     * Libelle affiche dans l'application.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Facturation => 'Facturation',
            self::Chantier => 'Chantier',
        };
    }
}
