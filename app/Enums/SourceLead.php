<?php

namespace App\Enums;

/**
 * Source d'un lead (tache 3.4) : d'ou vient le contact. Liste fermee
 * (et non du texte libre) pour pouvoir compter d'ou viennent les clients.
 */
enum SourceLead: string
{
    case BoucheAOreille = 'bouche_a_oreille';
    case SiteWeb = 'site_web';
    case ReseauxSociaux = 'reseaux_sociaux';
    case AncienClient = 'ancien_client';
    case Recommandation = 'recommandation';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::BoucheAOreille => 'Bouche-à-oreille',
            self::SiteWeb => 'Site web',
            self::ReseauxSociaux => 'Réseaux sociaux',
            self::AncienClient => 'Ancien client',
            self::Recommandation => 'Recommandation',
            self::Autre => 'Autre',
        };
    }
}
