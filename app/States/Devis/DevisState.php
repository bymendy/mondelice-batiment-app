<?php

namespace App\States\Devis;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Machine à états du Devis (module Devis, tâche 3.6).
 *
 * 13 statuts en 3 macro-états :
 *  - brouillon : En préparation, En attente de dérogation, Prêt à envoyer ;
 *  - envoyé : Envoyé, Délivré, Consulté, Relance 1, Relance 2, Négociation ;
 *  - décidé : Accepté, Refusé, Expiré, Annulé.
 *
 * La colonne `statut` de la table `devis` garde le nom du statut
 * (`en_preparation`, `envoye`, `relance_1`...). Le modèle Devis ne la convertit
 * pas en objet : le service ChangementStatutDevis demande à cette
 * configuration si un passage est permis (isTransitionAllowed avec les noms).
 * Toute transition absente d'ici est interdite.
 *
 * Passage humain vers « Envoyé » : endpoint dédié (POST /devis/{id}/envoyer),
 * jamais une mise à jour générique du statut (décision 2 du cadre IA
 * Responsable, voir Mondelice_Developpement_IA_Responsable.tex).
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
     * Déclare le statut par défaut et la liste exhaustive des passages
     * autorisés (spatie/laravel-model-states).
     *
     * @return StateConfig
     */
    public static function config(): StateConfig
    {
        $apresEnvoi = [Envoye::class, Delivre::class, Consulte::class, Relance1::class, Relance2::class, Negociation::class];

        $config = parent::config()
            ->default(EnPreparation::class)
            // Brouillon
            ->allowTransition(EnPreparation::class, EnAttenteDerogation::class)
            ->allowTransition(EnAttenteDerogation::class, EnPreparation::class)
            ->allowTransition(EnAttenteDerogation::class, PretAEnvoyer::class)
            ->allowTransition(EnPreparation::class, PretAEnvoyer::class)
            ->allowTransition(PretAEnvoyer::class, EnPreparation::class)
            // Envoi : action humaine explicite
            ->allowTransition([EnPreparation::class, PretAEnvoyer::class], Envoye::class)
            // Envoi suivi et relances (automatiques, tâche 3.7)
            ->allowTransition(Envoye::class, Delivre::class)
            ->allowTransition([Envoye::class, Delivre::class], Consulte::class)
            ->allowTransition([Envoye::class, Delivre::class, Consulte::class], Relance1::class)
            ->allowTransition(Relance1::class, Relance2::class)
            // Négociation : manuelle, depuis n'importe quel statut envoyé
            ->allowTransition([Envoye::class, Delivre::class, Consulte::class, Relance1::class, Relance2::class], Negociation::class);

        // Issues possibles depuis tout statut « en attente de réponse client »
        foreach ([Accepte::class, Refuse::class, Expire::class, Annule::class] as $issue) {
            $config->allowTransition($apresEnvoi, $issue);
        }

        return $config;
    }
}
