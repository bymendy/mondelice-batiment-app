<?php

namespace App\States\Devis;

/**
 * Version non retenue : remplacée par une version plus récente, ou écartée
 * parce que le client a accepté une autre version (lot « versions du devis »).
 * Ce statut est posé par l'application, jamais à la main. Il est définitif :
 * pour repartir d'une version non retenue, on en crée une nouvelle version.
 * Macro-état : Décidé. Famille de badge UI : Neutre (gris).
 *
 * Passages autorisés : voir DevisState::config().
 */
class NonRetenu extends DevisState
{
    /**
     * Nom enregistré dans la colonne `statut` de la table `devis`.
     *
     * @var string|null
     */
    public static ?string $name = 'non_retenu';

    /**
     * @return string
     */
    public function label(): string
    {
        return 'Non retenue';
    }
}
