<?php

namespace App\Enums;

/**
 * Type de seuil fixe par la Direction (Parametres du compte, § 4.2).
 *
 * Seuls les types deja controles par l'application figurent ici :
 *  - marge minimum sur la main-d'oeuvre et sur les fournitures : prix force
 *    d'un article de la Bibliotheque de prix (lot 6) ;
 *  - marge globale minimum et remise maximum : devis (lot D4b).
 *
 * La marge est exprimee en pourcentage du PRIX DE VENTE, comme le taux de marge
 * deja affiche (marge / prix de vente). La remise maximum est un pourcentage
 * du total HT avant remises.
 */
enum TypeSeuil: string
{
    case MargeMainOeuvre = 'marge_mo_min';
    case MargeFournitures = 'marge_fournitures_min';
    case MargeGlobaleDevis = 'marge_globale_min';
    case RemiseMaximum = 'remise_max';

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
            self::MargeGlobaleDevis => 'Marge globale minimum d\'un devis',
            self::RemiseMaximum => 'Remise maximum sur un devis',
        };
    }
}
