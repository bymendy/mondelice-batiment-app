<?php

namespace App\Listeners;

use App\Events\AutomationTestEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener de verification du moteur d'automatisation (tache 3.2).
 *
 * Implements ShouldQueue : Laravel pousse automatiquement ce listener
 * sur la file Redis (QUEUE_CONNECTION=redis) au lieu de l'executer en
 * synchrone -- c'est le mecanisme qui, a partir de la tache 3.7, fera
 * tourner les automatisations (AUTO-01 a AUTO-10) en arriere-plan sans
 * bloquer l'utilisateur.
 *
 * @throws \Throwable Toute exception levee dans handle() est capturee
 *                     par le worker et remet le job en echec (table
 *                     failed_jobs) plutot que de faire planter la requete
 *                     HTTP qui a declenche l'evenement -- avantage cle
 *                     de l'execution en file plutot qu'en synchrone.
 */
class LogAutomationTestListener implements ShouldQueue
{
    /**
     * @param AutomationTestEvent $event
     * @return void
     */
    public function handle(AutomationTestEvent $event): void
    {
        Log::info('[AUTOMATISATION] Listener execute via la file Redis : ' . $event->message);
    }
}
