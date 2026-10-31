<?php

namespace App\States\Devis;

/**
 * Première relance envoyée, client toujours sans réponse.
 * Famille de badge UI : Alerte / attention (orange).
 *
 * Transitions sortantes autorisées : -> Relance2, -> Accepte, -> Refuse,
 * -> Expire (voir DevisState::config()).
 */
class Relance1 extends DevisState
{
    public function label(): string
    {
        return 'Relance 1';
    }
}
