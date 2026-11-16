<?php

namespace App\States\Devis;

/**
 * Devis envoyé au client, en attente de réponse. Déclenche les relances J+3 / J+7 / J+10 (tâche 3.7).
 * Macro-état : Envoyé. Famille de badge UI : En cours (bleu).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Envoye extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'envoye';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Envoyé';
    }
}
