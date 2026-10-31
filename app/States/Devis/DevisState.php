<?php

namespace App\States\Devis;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Machine à états du Devis (module Devis, tâche 3.6).
 *
 * Statuts gérés : EnPreparation -> Envoye -> Relance1 -> Relance2 -> Negociation,
 * avec Accepte / Refuse / Expire atteignables depuis Envoye, Relance1, Relance2
 * ou Negociation (un client peut accepter/refuser dès l'envoi, sans attendre
 * les relances — règle confirmée le 25/10/2026).
 *
 * Usage sur le modèle Eloquent :
 *   protected $casts = ['statut' => DevisState::class];
 *
 * Changer de statut :
 *   $devis->statut->transitionTo(Envoye::class);
 *
 * @throws \Spatie\ModelStates\Exceptions\TransitionNotFound
 *   Levée par transitionTo() si la transition demandée n'est pas déclarée
 *   dans config() ci-dessous — c'est le verrou recherché (ex. impossible de
 *   facturer un devis non accepté, de façon générale : impossible de sauter
 *   directement vers un statut non autorisé).
 *   Pour tester sans lever d'exception (ex. pour griser un bouton dans l'UI),
 *   utiliser DevisState::config()->isTransitionAllowed(Depuis::class, Vers::class)
 *   qui retourne un booléen.
 */
abstract class DevisState extends State
{
    /**
     * Libellé lisible du statut, affiché dans l'UI (badges, timeline).
     *
     * @return string
     */
    abstract public function label(): string;

    /**
     * Déclare le statut par défaut à la création d'un Devis, et la liste
     * exhaustive des transitions autorisées. Toute transition absente d'ici
     * est interdite par le framework (spatie/laravel-model-states).
     *
     * @return StateConfig
     */
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(EnPreparation::class)
            // Chaîne de relance normale
            ->allowTransition(EnPreparation::class, Envoye::class)
            ->allowTransition(Envoye::class, Relance1::class)
            ->allowTransition(Relance1::class, Relance2::class)
            ->allowTransition(Relance2::class, Negociation::class)
            // Issues possibles depuis n'importe quel statut "en attente de réponse client"
            ->allowTransition(
                [Envoye::class, Relance1::class, Relance2::class, Negociation::class],
                Accepte::class
            )
            ->allowTransition(
                [Envoye::class, Relance1::class, Relance2::class, Negociation::class],
                Refuse::class
            )
            ->allowTransition(
                [Envoye::class, Relance1::class, Relance2::class, Negociation::class],
                Expire::class
            );
    }
}
