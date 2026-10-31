<?php

namespace App\States\Devis;

/**
 * Client en discussion active sur le contenu ou le prix du devis.
 * Famille de badge UI : En cours / action requise (bleu).
 *
 * Transitions sortantes autorisées : -> Accepte, -> Refuse, -> Expire
 * (voir DevisState::config()).
 */
class Negociation extends DevisState
{
    public function label(): string
    {
        return 'Négociation';
    }
}
