<?php

namespace App\States\Devis;

/**
 * Devis non répondu au-delà du délai de relance — statut final négatif.
 * Famille de badge UI : Critique / bloquant (rouge, verrouillé niveau
 * système, non personnalisable en marque blanche SaaS).
 *
 * Statut final : aucune transition sortante déclarée.
 */
class Expire extends DevisState
{
    public function label(): string
    {
        return 'Expiré';
    }
}
