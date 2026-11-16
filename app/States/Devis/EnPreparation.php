<?php

namespace App\States\Devis;

/**
 * Statut d'entrée du devis : brouillon en cours de rédaction.
 * Macro-état : Brouillon. Famille de badge UI : Neutre (gris).
 *
 * Passages autorisés : voir DevisState::config().
 */
class EnPreparation extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'en_preparation';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'En préparation';
    }
}
