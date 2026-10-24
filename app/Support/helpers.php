<?php

/**
 * Retourne l'identifiant du tenant actuellement initialisé pour la requête
 * en cours.
 *
 * Fonction globale utilisée par le trait BelongsToTenant pour filtrer
 * automatiquement les requêtes Eloquent. Retourne null si aucun tenant n'a
 * encore été initialisé (avant que IdentifyTenantByUser ne s'exécute, ou
 * si personne n'est connecté).
 *
 * IMPORTANT : s'appuie sur tenancy()->tenant (le tenant déjà initialisé par
 * le middleware IdentifyTenantByUser), jamais sur Auth::user()->tenant_id
 * directement. La version précédente appelait Auth::user(), ce qui créait
 * une récursion infinie : résoudre l'utilisateur connecté interroge la
 * table "users", filtrée par le global scope de BelongsToTenant, qui
 * rappelait tenant_id(), qui rappelait Auth::user()... --- la boucle ne se
 * termine jamais (observé via /api/user, la trace de retrieveById() se
 * répétant plus de 100 fois). tenancy()->tenant est indépendant de la
 * résolution de l'utilisateur, donc sans boucle possible.
 *
 * @return string|null L'UUID du tenant initialisé, ou null.
 */
if (! function_exists('tenant_id')) {
    function tenant_id(): ?string
    {
        return tenancy()->initialized
            ? tenancy()->tenant->getTenantKey()
            : null;
    }
}
