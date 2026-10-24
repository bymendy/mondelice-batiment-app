<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Par défaut, la notification ResetPassword construit son lien via
         * route('password.reset', ...) --- une route Blade qui n'existe pas
         * ici, puisque la page de saisie du nouveau mot de passe est rendue
         * par Next.js, pas par Laravel. On pointe donc le lien vers le
         * frontend, avec le token et l'email en query string.
         */
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return config('app.frontend_url').'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        /**
         * Journal d'actions (tâche 2.8) : connexion, déconnexion et échec de
         * connexion sont journalisés par App\Listeners\LogSuccessfulLogin,
         * LogSuccessfulLogout et LogFailedLogin --- découverts et enregistrés
         * automatiquement par Laravel (event auto-discovery, basée sur le
         * typage de la méthode handle()), donc pas d'Event::listen() ici
         * pour éviter un double enregistrement.
         */
    }
}
