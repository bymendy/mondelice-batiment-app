<?php

namespace App\States\Devis;

/**
 * Fin de la durée de validité sans réponse (atteint automatiquement).
 * Macro-état : Décidé. Famille de badge UI : Échec (rouge).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Expire extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'expire';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Expiré';
    }
}
