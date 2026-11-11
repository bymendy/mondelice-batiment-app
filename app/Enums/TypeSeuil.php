<?php

namespace App\Enums;

/**
 * Type de seuil plancher fixe par la Direction (Parametres du compte, § 4.2).
 *
 * Seuls les types deja controles par l'application figurent ici ; les autres
 * (taux horaire minimum, frais generaux, marge globale du devis, remise
 * maximum) s'ajouteront avec le module qui les utilise.
 *
 * La marge est exprimee en pourcentage du PRIX DE VENTE, comme le taux de marge
 * deja affiche (marge / prix de vente).
 */
enum TypeSeuil: string
{
    case MargeMainOeuvre = 'marge_mo_min';
    case MargeFournitures = 'marge_fournitures_min';

    /**
     * Libelle lisible pour l'UI.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::MargeMainOeuvre => 'Marge minimum sur la main-d\'œuvre',
            self::MargeFournitures => 'Marge minimum sur les fournitures',
        };
    }
}
