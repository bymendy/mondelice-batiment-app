<?php

namespace App\States\Devis;

/**
 * Statut d'entrée du Devis, avant tout envoi au client.
 * Famille de badge UI : Neutre / en attente (gris).
 *
 * Transition sortante autorisée : -> Envoye (voir DevisState::config()).
 * Le passage vers Envoye doit être déclenché par une action humaine
 * explicite (endpoint d'envoi dédié, pas une mise à jour générique de
 * statut) — contrainte actée pour rester compatible avec la V3 assistant
 * vocal (Mondelice_Developpement_IA_Responsable.tex, décision 2).
 */
class EnPreparation extends DevisState
{
    public function label(): string
    {
        return 'En préparation';
    }
}
