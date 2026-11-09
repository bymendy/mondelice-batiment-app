<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Levee quand on tente de modifier ou de supprimer une ligne « de reference »
 * (tenant_id NULL, partagee par toutes les entreprises) sans autorisation
 * explicite.
 *
 * Les references sont gerees uniquement par l'editeur (seeders, commandes
 * d'administration) via autoriserModificationReference().
 */
class ReferenceNonModifiableException extends RuntimeException
{
}
