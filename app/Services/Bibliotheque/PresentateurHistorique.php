<?php

namespace App\Services\Bibliotheque;

use App\Models\Activity;
use App\Models\User;

/**
 * Forme JSON d'une entree du journal des prix (Bibliotheque de prix, lot 7).
 *
 * Les prix d'achat (`JournalPrix::CHAMPS_SENSIBLES`) sont retires des valeurs
 * `avant` et `apres` pour qui n'a pas `bibliotheque.voir_prix_achat`. L'entree
 * reste visible, avec `details_masques` a vrai si des valeurs ont ete retirees.
 */
final class PresentateurHistorique
{
    /**
     * @param bool $voirPrixAchat L'utilisateur peut voir les prix d'achat.
     */
    public function __construct(private readonly bool $voirPrixAchat)
    {
    }

    /**
     * @param User|null $utilisateur
     * @return self
     */
    public static function pour(?User $utilisateur): self
    {
        return new self($utilisateur !== null && $utilisateur->can('bibliotheque.voir_prix_achat'));
    }

    /**
     * @param Activity $entree
     * @return array<string, mixed>
     */
    public function presenter(Activity $entree): array
    {
        $proprietes = collect($entree->properties)->all();
        $avant = $proprietes['avant'] ?? null;
        $apres = $proprietes['apres'] ?? null;
        $masque = false;

        if (! $this->voirPrixAchat) {
            [$avant, $retireAvant] = $this->retirerSensibles($avant);
            [$apres, $retireApres] = $this->retirerSensibles($apres);
            $masque = $retireAvant || $retireApres;
        }

        unset($proprietes['avant'], $proprietes['apres']);

        return [
            'id' => $entree->id,
            'evenement' => $entree->event,
            'description' => $entree->description,
            'auteur' => $entree->causer !== null ? ['id' => $entree->causer->getKey(), 'nom' => $entree->causer->name] : null,
            'declenche_par' => $entree->triggered_by ?? 'human',
            'regle_automatisation' => $entree->automation_rule,
            'date' => $entree->created_at?->toIso8601String(),
            'avant' => $avant,
            'apres' => $apres,
            'contexte' => $proprietes,
            'details_masques' => $masque,
        ];
    }

    /**
     * Retire les champs sensibles d'un bloc `avant` ou `apres`, a tous les niveaux
     * (un sous-detail enregistre d'un coup contient une liste de lignes).
     *
     * @param mixed $bloc Valeurs `avant` ou `apres` d'une entree.
     * @return array{0: mixed, 1: bool} Le bloc sans les champs sensibles, et vrai si on en a retire.
     */
    private function retirerSensibles(mixed $bloc): array
    {
        if (! is_array($bloc)) {
            return [$bloc, false];
        }

        $retire = false;

        foreach ($bloc as $cle => $valeur) {
            if (in_array($cle, JournalPrix::CHAMPS_SENSIBLES, true)) {
                unset($bloc[$cle]);
                $retire = true;

                continue;
            }

            if (is_array($valeur)) {
                [$bloc[$cle], $dedans] = $this->retirerSensibles($valeur);
                $retire = $retire || $dedans;
            }
        }

        return [$bloc, $retire];
    }
}
