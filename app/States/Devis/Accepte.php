<?php

namespace App\States\Devis;

/**
 * Devis accepté : en ligne par le client, ou à la main avec preuve. Ouvre la suite du workflow.
 * Macro-état : Décidé. Famille de badge UI : Succès (vert).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Accepte extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'accepte';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Accepté';
    }
}
