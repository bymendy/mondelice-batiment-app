<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evenement de test pour verifier le cablage du moteur d'automatisation
 * (tache 3.2) : Event -> Listener en file -> Redis -> worker.
 *
 * Usage reel (a partir de la tache 3.6) : les hooks onEnterState() des
 * classes de app/States/{Module}/ declencheront des evenements du meme
 * type (ex. DevisEnvoye, ChantierReceptionne) a la place de celui-ci.
 *
 * @param string $message Contenu arbitraire transporte par l'evenement,
 *                         journalise par le listener pour prouver que
 *                         le trajet complet (event -> queue -> worker)
 *                         fonctionne.
 */
class AutomationTestEvent
{
    use Dispatchable;

    public function __construct(public readonly string $message)
    {
    }
}
