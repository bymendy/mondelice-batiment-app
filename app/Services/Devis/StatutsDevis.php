<?php

namespace App\Services\Devis;

use App\States\Devis\DevisState;

/**
 * Libellés et macro-états des statuts du devis, pour les réponses de l'API.
 */
class StatutsDevis
{
    /** @var list<string> */
    public const BROUILLON = ['en_preparation', 'en_attente_derogation', 'pret_a_envoyer'];

    /** @var list<string> */
    public const ENVOYE = ['envoye', 'delivre', 'consulte', 'relance_1', 'relance_2', 'negociation'];

    /** @var list<string> */
    public const DECIDE = ['accepte', 'refuse', 'expire', 'annule'];

    /**
     * Libellé du statut (« Relance 1 »...). Un statut inconnu est rendu tel quel.
     *
     * @param string $statut
     * @return string
     */
    public static function libelle(string $statut): string
    {
        $classe = DevisState::getStateMapping()->get($statut);

        return $classe === null ? $statut : (new $classe(null))->label();
    }

    /**
     * Macro-état : brouillon, envoye ou decide.
     *
     * @param string $statut
     * @return string
     */
    public static function macroEtat(string $statut): string
    {
        return match (true) {
            in_array($statut, self::ENVOYE, true) => 'envoye',
            in_array($statut, self::DECIDE, true) => 'decide',
            default => 'brouillon',
        };
    }
}
