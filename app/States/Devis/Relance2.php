<?php

namespace App\States\Devis;

/**
 * Deuxième relance envoyée, client toujours sans réponse.
 * Famille de badge UI : Alerte / attention (orange).
 *
 * Transitions sortantes autorisées : -> Negociation, -> Accepte, -> Refuse,
 * -> Expire (voir DevisState::config()).
 */
class Relance2 extends DevisState
{
    public function label(): string
    {
        return 'Relance 2';
    }
}
