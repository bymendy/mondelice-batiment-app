<?php

namespace App\States\Devis;

/**
 * Le client a ouvert le devis (envoi suivi, atteint automatiquement).
 * Macro-état : Envoyé. Famille de badge UI : En cours (bleu).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Consulte extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'consulte';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Consulté';
    }
}
