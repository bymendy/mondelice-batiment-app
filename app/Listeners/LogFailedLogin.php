<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;

/**
 * Journalise chaque tentative de connexion échouée.
 *
 * Déclenché automatiquement par Auth::attempt() en cas d'échec (avant
 * même que LoginRequest::authenticate() ne lève sa ValidationException).
 * Aucun utilisateur authentifié n'est disponible comme "causer" --- on
 * journalise l'email tenté et l'IP, sans lier l'entrée à un compte
 * utilisateur (l'email soumis peut ne correspondre à aucun compte réel,
 * donc aucun tenant n'est résolu : tenant_id restera vide sur cette
 * entrée, comme prévu par App\Models\Activity).
 */
class LogFailedLogin
{
    /**
     * @param Failed $event L'événement d'échec d'authentification, portant
     *                       les identifiants soumis (sans le mot de passe
     *                       en clair --- Laravel ne l'y inclut jamais).
     * @return void
     */
    public function handle(Failed $event): void
    {
        activity()
            ->withProperties([
                'email' => $event->credentials['email'] ?? null,
                'ip' => request()->ip(),
            ])
            ->log('Échec de connexion.');
    }
}
