<?php

namespace App\Exceptions;

use DomainException;

/**
 * Levee quand une ligne de main-d'oeuvre cite une qualification (code de taux
 * horaire) qui n'existe pas pour l'entreprise : le prix ne peut pas etre
 * calcule, et on ne le devine jamais.
 */
class QualificationInconnueException extends DomainException
{
}
