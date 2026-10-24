<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Logout;

/**
 * Journalise chaque déconnexion.
 *
 * Déclenché automatiquement par Auth::guard('web')->logout() (voir
 * AuthenticatedSessionController::destroy()).
 */
class LogSuccessfulLogout
{
    /**
     * @param Logout $event L'événement de déconnexion. L'utilisateur peut
     *                       être null dans certains cas limites (session
     *                       déjà invalide) --- on ignore alors l'événement.
     * @return void
     */
    public function handle(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        activity()
            ->causedBy($event->user)
            ->withProperties(['ip' => request()->ip()])
            ->log('Déconnexion.');
    }
}
