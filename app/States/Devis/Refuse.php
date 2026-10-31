<?php

namespace App\States\Devis;

/**
 * Devis refusé par le client — statut final négatif.
 * Famille de badge UI : Critique / bloquant (rouge, verrouillé niveau
 * système, non personnalisable en marque blanche SaaS).
 *
 * Statut final : aucune transition sortante déclarée.
 */
class Refuse extends DevisState
{
    public function label(): string
    {
        return 'Refusé';
    }
}
