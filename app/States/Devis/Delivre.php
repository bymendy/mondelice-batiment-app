<?php

namespace App\States\Devis;

/**
 * E-mail délivré au client (envoi suivi, atteint automatiquement).
 * Macro-état : Envoyé. Famille de badge UI : En cours (bleu).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Delivre extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'delivre';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Délivré';
    }
}
