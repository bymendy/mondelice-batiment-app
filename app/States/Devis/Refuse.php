<?php

namespace App\States\Devis;

/**
 * Devis refusé par le client.
 * Macro-état : Décidé. Famille de badge UI : Échec (rouge).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Refuse extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'refuse';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Refusé';
    }
}
