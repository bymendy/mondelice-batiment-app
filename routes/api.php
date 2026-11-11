<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Routes API, consommées par le frontend Next.js (préfixe /api automatique).
 *
 * Toute route placée derrière le middleware "auth:sanctum" exige une
 * authentification valide (cookie de session en mode SPA, ou token Bearer
 * pour un client mobile/externe en V2) --- voir Mondelice_Phase2_Backend.tex.
 */

/**
 * Route de test : renvoie l'utilisateur actuellement authentifié.
 * Sert à vérifier que Sanctum fonctionne, avant de construire les
 * contrôleurs métier (inscription, connexion, puis modules applicatifs).
 *
 * @param Request $request La requête HTTP, dont le contexte d'authentification
 *                          a été résolu par le middleware auth:sanctum.
 * @return \App\Models\User|null L'utilisateur authentifié, au format JSON.
 */
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/**
 * Inscription : crée un tenant + un utilisateur, puis connecte
 * immédiatement l'utilisateur. Endpoint public (pas de middleware auth).
 */
Route::post('/register', [\App\Http\Controllers\Auth\RegisteredUserController::class, 'store']);

/**
 * Connexion / déconnexion. "login" est public ; "logout" exige une session
 * active (middleware auth:sanctum).
 */
Route::post('/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'store']);
Route::middleware('auth:sanctum')->post('/logout', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'destroy']);

/**
 * Réinitialisation de mot de passe (« mot de passe oublié »). Les deux
 * routes sont publiques : la première est protégée par la réponse
 * générique (pas d'énumération de comptes, voir
 * PasswordResetLinkController) ; la seconde est protégée par la
 * possession du token reçu par email (pas par une session).
 */
Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'store']);
Route::post('/reset-password', [\App\Http\Controllers\Auth\NewPasswordController::class, 'store']);

/**
 * Authentification à deux facteurs (MFA, TOTP) --- tâche 2.15.
 *
 * Toutes ces routes exigent une session active (auth:sanctum) : le MFA
 * est une opération sur SON PROPRE compte, jamais sur celui d'un tiers.
 * Décision sécurité : obligatoire pour Direction/Administratif/Ressources
 * Humaines, recommandé pour Commercial/Conducteur travaux, non nécessaire
 * pour Salarié terrain (CONTEXTE_SECURITE_RGPD_IA.md, §1.2 point 1).
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/2fa/enable', [\App\Http\Controllers\Auth\TwoFactorController::class, 'enable']);
    Route::post('/2fa/confirm', [\App\Http\Controllers\Auth\TwoFactorController::class, 'confirm']);
    Route::post('/2fa/disable', [\App\Http\Controllers\Auth\TwoFactorController::class, 'disable']);
});

/**
 * Vérification du code MFA au moment de la connexion --- tâche 2.15.
 *
 * Route PUBLIQUE volontairement (pas de middleware auth:sanctum) :
 * l'utilisateur n'est pas encore connecté à ce stade, par construction
 * (voir AuthenticatedSessionController::store() et
 * TwoFactorController::loginVerify()). La session existe déjà (posée par
 * /sanctum/csrf-cookie puis /api/login), c'est elle qui porte l'identifiant
 * en attente --- jamais Sanctum, qui exige justement une connexion déjà
 * terminée.
 */
Route::post('/2fa/login-verify', [\App\Http\Controllers\Auth\TwoFactorController::class, 'loginVerify']);

/**
 * Bibliotheque de prix (tache 3.3) : catalogue d'articles/ouvrages
 * reutilisable par les lignes de devis. Catalogue partage par tout le
 * tenant (filtrage tenant_id automatique via BelongsToTenant, voir
 * app/Models/ArticlePrix.php) -- pas de filtrage par utilisateur.
 */
Route::middleware(['auth:sanctum', 'require.mfa'])->group(function () {
    Route::apiResource('bibliotheque-prix', \App\Http\Controllers\ArticlePrixController::class)
        ->parameters(['bibliotheque-prix' => 'articlePrix']);
    Route::post('bibliotheque-prix/{articlePrix}/personnaliser', [\App\Http\Controllers\ArticlePrixController::class, 'personnaliser']);
    Route::post('bibliotheque-prix/{articlePrix}/publier', [\App\Http\Controllers\ArticlePrixController::class, 'publier']);
    Route::prefix('bibliotheque-prix/{articlePrix}/composants')
        ->controller(\App\Http\Controllers\ArticleComposantController::class)
        ->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::put('ordre', 'reordonner');
            Route::put('{composant}', 'update')->whereNumber('composant');
            Route::delete('{composant}', 'destroy')->whereNumber('composant');
        });
});

/**
 * Contact / Client (tache 3.4) : racine du modele de donnees. Les
 * permissions (contacts.*) sont appliquees par le controleur lui-meme
 * (interface HasMiddleware), pas ici.
 */
Route::middleware(['auth:sanctum', 'require.mfa'])->group(function () {
    Route::apiResource('clients', \App\Http\Controllers\ClientController::class);
    Route::apiResource('contacts', \App\Http\Controllers\ContactController::class);
    Route::post('clients/{client}/contacts', [\App\Http\Controllers\ClientContactController::class, 'store']);
    Route::delete('clients/{client}/contacts/{contact}', [\App\Http\Controllers\ClientContactController::class, 'destroy']);
});

/**
 * Reglages de l'entreprise (tache 3.4 bis). Permission parametres.gerer
 * appliquee par le controleur (HasMiddleware).
 */
Route::middleware(['auth:sanctum', 'require.mfa'])->group(function () {
    Route::get('parametres', [\App\Http\Controllers\ParametreController::class, 'index']);
    Route::put('parametres/{cle}', [\App\Http\Controllers\ParametreController::class, 'update']);
    Route::get('parametres/seuils', [\App\Http\Controllers\SeuilTarifaireController::class, 'index']);
    Route::put('parametres/seuils/{type}', [\App\Http\Controllers\SeuilTarifaireController::class, 'update']);
});
