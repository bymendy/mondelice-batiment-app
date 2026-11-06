<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Levee quand un modele reserve a une entreprise est lu ou cree alors
 * qu'aucune entreprise (tenant) n'est identifiee.
 *
 * Plutot que de laisser la requete renvoyer les donnees de toutes les
 * entreprises (fuite), l'application refuse. En production, cette exception
 * produit une erreur 500 visible dans les journaux : c'est un defaut de code
 * (middleware manquant, tache en file sans contexte, commande sans tenant) a
 * corriger, jamais une situation normale.
 *
 * Pour une lecture volontaire hors entreprise (commande d'administration),
 * utiliser explicitement Modele::sansFiltreTenant().
 */
class TenantNonIdentifieException extends RuntimeException
{
}
