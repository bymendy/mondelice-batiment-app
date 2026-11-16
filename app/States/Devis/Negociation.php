<?php

namespace App\States\Devis;

/**
 * Le client discute le devis. Passage manuel.
 * Macro-état : Envoyé. Famille de badge UI : En cours (bleu).
 *
 * Passages autorisés : voir DevisState::config().
 */
class Negociation extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'negociation';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Négociation';
    }
}
