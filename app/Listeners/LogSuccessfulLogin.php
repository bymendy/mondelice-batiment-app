<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * Journalise chaque connexion réussie dans le journal d'actions.
 *
 * Déclenché automatiquement par Laravel à chaque authentification
 * réussie (Auth::attempt() dans LoginRequest::authenticate()) --- aucune
 * modification des contrôleurs n'est nécessaire.
 */
class LogSuccessfulLogin
{
    /**
     * @param Login $event L'événement d'authentification réussie, portant
     *                      l'utilisateur authentifié.
     * @return void
     */
    public function handle(Login $event): void
    {
        activity()
            ->causedBy($event->user)
            ->withProperties(['ip' => request()->ip()])
            ->log('Connexion réussie.');
    }
}
