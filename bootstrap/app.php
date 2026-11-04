<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * Point d'entrée de la configuration de l'application (remplace le fichier
 * app/Http/Kernel.php des anciennes versions de Laravel).
 *
 * Ce fichier déclare, dans l'ordre :
 *  - les groupes de routes chargés (web, API, commandes, health-check) ;
 *  - les middlewares globaux ajoutés aux groupes existants ;
 *  - la façon dont les exceptions sont rendues (JSON pour l'API, HTML sinon).
 *
 * @return Application L'instance d'application Laravel, entièrement configurée.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Routes classiques (sessions/cookies) --- peu utilisées ici, le
        // frontend étant Next.js plutôt que des vues Blade.
        web: __DIR__.'/../routes/web.php',

        // Routes API (fichier routes/api.php, préfixées automatiquement par
        // /api). C'est ici que vivent tous les endpoints consommés par le
        // frontend Next.js : inscription, connexion, puis tous les modules
        // métier (clients, devis, chantiers...).
        api: __DIR__.'/../routes/api.php',

        // Commandes artisan personnalisées (routes/console.php).
        commands: __DIR__.'/../routes/console.php',

        // Endpoint de supervision (/up), utilisé par les outils de
        // monitoring pour vérifier que l'application répond.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * Active l'authentification SPA de Sanctum sur le groupe "api".
         *
         * Par défaut, le groupe "api" ne gère ni session ni cookies (il est
         * pensé pour être "stateless"). statefulApi() y ajoute justement ce
         * qu'il faut --- session, cookies chiffrés, et le middleware
         * EnsureFrontendRequestsAreStateful de Sanctum --- pour que les
         * routes API (routes/api.php) puissent ouvrir une session et poser
         * un cookie, exactement comme une route "web" classique. C'est la
         * méthode recommandée par Laravel pour ce cas précis (SPA + Sanctum
         * + groupe api), plutôt que d'ajouter le middleware Sanctum "à la
         * main" sur le groupe web (ce qui ne couvre pas les routes api).
         */
        $middleware->statefulApi();

        /**
         * Middleware maison qui initialise le tenant courant à partir du
         * tenant_id de l'utilisateur authentifié. Ajouté aux deux groupes
         * (web et api) car les routes applicatives (clients, devis,
         * chantiers...) vivront dans routes/api.php, pas routes/web.php.
         */
        $middleware->web(append: [
            \App\Http\Middleware\IdentifyTenantByUser::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\IdentifyTenantByUser::class,
        ]);

        /**
         * Ordre d'execution : le tenant doit etre identifie AVANT que Laravel
         * charge les modeles lies a l'URL (SubstituteBindings, ex.
         * /api/contacts/{contact}). Sans cela, le chargement se fait sans
         * filtre d'entreprise et une entreprise peut lire, modifier ou
         * supprimer l'enregistrement d'une autre en connaissant son identifiant.
         *
         * Dans la liste de priorite de Laravel, SubstituteBindings passe
         * apres l'authentification : le tenant est donc identifie apres
         * l'authentification et avant le chargement des modeles.
         */
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\IdentifyTenantByUser::class,
        );

        /**
         * Alias des middlewares de Spatie Permission (tâche 2.13), pour
         * pouvoir écrire ->middleware('permission:contacts.voir') ou
         * ->middleware('role:Direction') sur les futures routes métier
         * (Phases 3+), sans avoir à référencer le nom de classe complet.
         */
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

            /**
             * Tâche 2.15 : impose l'activation du MFA sur une route métier,
             * pour les rôles où elle est obligatoire (voir
             * App\Http\Middleware\EnsureTwoFactorIsSetUp). À poser sur les
             * futures routes métier des phases 3+, jamais sur /api/2fa/enable
             * ou /api/2fa/confirm (sous peine de bloquer l'utilisateur avant
             * même qu'il puisse activer son MFA).
             */
            'require.mfa' => \App\Http\Middleware\EnsureTwoFactorIsSetUp::class,
        ]);

        /**
         * Par défaut, Laravel redirige un visiteur non authentifié vers une
         * route nommée "login" (page de connexion classique, rendue par
         * Laravel lui-même). Cette route n'existe pas ici : la page de
         * connexion est rendue par Next.js, pas par Laravel.
         *
         * Sans ce réglage, une requête vers /api/* qui n'envoie pas
         * explicitement l'en-tête "Accept: application/json" provoque une
         * erreur 500 "Route [login] not defined" au lieu d'un simple 401.
         *
         * @param Request $request La requête HTTP entrante.
         * @return string|null null pour toute requête /api/* (pas de
         *                      redirection, juste un 401 JSON) ; l'URL de
         *                      la route "login" sinon.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * Force une réponse JSON (plutôt qu'une page d'erreur HTML) pour
         * toute requête qui cible l'API (préfixe /api/*) ou qui déclare
         * explicitement attendre du JSON (en-tête Accept: application/json),
         * ce qui couvre le cas du frontend Next.js consommant l'API.
         *
         * @param Request $request La requête HTTP entrante.
         * @return bool true si la réponse d'erreur doit être rendue en JSON.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
