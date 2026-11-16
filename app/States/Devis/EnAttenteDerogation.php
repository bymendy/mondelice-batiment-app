<?php

namespace App\States\Devis;

/**
 * Brouillon bloqué par un seuil de la Direction : la dérogation demandée n'est pas encore accordée (lot D4).
 * Macro-état : Brouillon. Famille de badge UI : À traiter (orange).
 *
 * Passages autorisés : voir DevisState::config().
 */
class EnAttenteDerogation extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'en_attente_derogation';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'En attente de dérogation';
    }
}
