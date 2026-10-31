<?php

namespace App\States\Devis;

/**
 * Devis envoyé au client, en attente de réponse.
 * Famille de badge UI : En cours / action requise (bleu).
 *
 * Transitions sortantes autorisées : -> Relance1, -> Accepte, -> Refuse,
 * -> Expire (voir DevisState::config()). Déclenche AUTO-01 (relances
 * programmées J+3/J+7/J+10, tâche 3.7).
 */
class Envoye extends DevisState
{
    public function label(): string
    {
        return 'Envoyé';
    }
}
