<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Levee a l'interieur de la transaction d'ecriture d'un devis quand un seuil
 * de la Direction n'est pas respecte : la transaction est annulee, puis le
 * refus est journalise et renvoye en 422 par ControleSeuilsDevis.
 */
class RefusSeuilDevisException extends RuntimeException
{
    /**
     * @param array{motif: string, valeur: float, seuil: float, message: string} $refus
     */
    public function __construct(public readonly array $refus)
    {
        parent::__construct($refus['message']);
    }
}
