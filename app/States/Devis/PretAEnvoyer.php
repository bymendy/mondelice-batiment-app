<?php

namespace App\States\Devis;

/**
 * Brouillon dont les contrôles avant envoi sont au vert.
 * Macro-état : Brouillon. Famille de badge UI : Neutre (gris).
 *
 * Passages autorisés : voir DevisState::config().
 */
class PretAEnvoyer extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'pret_a_envoyer';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Prêt à envoyer';
    }
}
