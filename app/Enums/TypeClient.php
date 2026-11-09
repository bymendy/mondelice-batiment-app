<?php

namespace App\Enums;

/**
 * Type de client (module Clients / CRM) : un particulier est identifie par
 * son nom/prenom, les autres types par leur raison sociale.
 */
enum TypeClient: string
{
    case Particulier = 'particulier';
    case Professionnel = 'professionnel';
    case Syndic = 'syndic';
    case Prescripteur = 'prescripteur';
    case Collectivite = 'collectivite';

    /**
     * Libelle lisible pour l'UI.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Particulier => 'Particulier',
            self::Professionnel => 'Professionnel',
            self::Syndic => 'Syndic',
            self::Prescripteur => 'Prescripteur',
            self::Collectivite => 'Collectivité',
        };
    }

    /**
     * Valeurs des types identifies par une raison sociale (tous sauf le
     * particulier).
     *
     * @return list<string>
     */
    public static function avecRaisonSociale(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type !== self::Particulier)
        ));
    }
}
