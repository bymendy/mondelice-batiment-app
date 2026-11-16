<?php

namespace App\States\Devis;

/**
 * Devis annulé par l'entreprise.
 * Macro-état : Décidé. Famille de badge UI : Neutre (gris).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Annule extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'annule';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Annulé';
    }
}
