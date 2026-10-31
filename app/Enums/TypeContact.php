<?php

namespace App\Enums;

/**
 * Type de contact (tache 3.4) : un particulier est identifie par son
 * nom/prenom, un professionnel par sa raison sociale (+ SIRET optionnel).
 */
enum TypeContact: string
{
    case Particulier = 'particulier';
    case Professionnel = 'professionnel';

    public function label(): string
    {
        return match ($this) {
            self::Particulier => 'Particulier',
            self::Professionnel => 'Professionnel',
        };
    }
}
