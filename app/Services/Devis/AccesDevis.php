<?php

namespace App\Services\Devis;

use App\Models\Devis;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Regles d'acces communes au devis, a ses lots et a ses lignes.
 *
 * Visibilite : un devis d'entreprise est visible par tous ceux qui ont
 * devis.voir ; un devis prive n'est visible que de son auteur et de la
 * Direction (opportunites.voir_toutes, reservee a la Direction). Un devis hors
 * perimetre est introuvable (404), pas interdit.
 */
class AccesDevis
{
    /**
     * Statuts dans lesquels un devis peut encore etre modifie (brouillon).
     *
     * @var list<string>
     */
    public const STATUTS_MODIFIABLES = ['en_preparation', 'en_attente_derogation', 'pret_a_envoyer'];

    /**
     * Devis visibles par l'utilisateur.
     *
     * @param User $utilisateur
     * @return Builder<Devis>
     */
    public static function visibles(User $utilisateur): Builder
    {
        $requete = Devis::query();

        // Lot D6 : permission propre au devis (elle remplace opportunites.voir_toutes).
        if (! $utilisateur->can('devis.voir_prives_autres')) {
            $requete->where(function (Builder $q) use ($utilisateur): void {
                $q->where('portee', 'entreprise')->orWhere('auteur_id', $utilisateur->getKey());
            });
        }

        return $requete;
    }

    /**
     * @param Devis $devis
     * @return bool
     */
    public static function estModifiable(Devis $devis): bool
    {
        return in_array($devis->statut, self::STATUTS_MODIFIABLES, true);
    }

    /**
     * Refuse (422) toute modification d'un devis qui n'est plus un brouillon.
     *
     * @param Devis $devis
     * @return void
     *
     * @throws ValidationException Si le devis n'est plus modifiable.
     */
    public static function exigerModifiable(Devis $devis): void
    {
        if (! self::estModifiable($devis)) {
            throw ValidationException::withMessages([
                'devis' => 'Ce devis n\'est plus modifiable : il a été envoyé ou décidé.',
            ]);
        }
    }

    /**
     * Cet utilisateur peut-il voir ce devis ? Même règle que `visibles()`,
     * pour un devis déjà chargé : un devis privé n'est vu que de son auteur
     * et de ceux qui ont `devis.voir_prives_autres` (la Direction).
     *
     * @param User $utilisateur
     * @param Devis $devis
     * @return bool
     */
    public static function peutVoir(User $utilisateur, Devis $devis): bool
    {
        return $devis->portee !== 'prive'
            || $devis->auteur_id === $utilisateur->getKey()
            || $utilisateur->can('devis.voir_prives_autres');
    }
}
