<?php

namespace App\States\Devis;

/**
 * Deuxième relance (J+7), atteinte automatiquement.
 * Macro-état : Envoyé. Famille de badge UI : À traiter (orange).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Relance2 extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'relance_2';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Relance 2';
    }
}
