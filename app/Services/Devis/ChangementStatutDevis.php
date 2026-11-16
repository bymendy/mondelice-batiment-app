<?php

namespace App\Services\Devis;

use App\Models\Devis;
use App\Models\User;
use App\States\Devis\DevisState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changement de statut d'un devis par une personne.
 *
 * Le passage doit être déclaré dans DevisState::config(). L'envoi fige les
 * totaux et pose la date d'envoi ; l'acceptation pose la date et la preuve.
 * Chaque changement est journalisé et fait suivre l'étape de l'affaire
 * (SynchronisationAffaireDevis), dans la même transaction.
 */
class ChangementStatutDevis
{
    /**
     * @param SynchronisationAffaireDevis $affaires
     */
    public function __construct(private readonly SynchronisationAffaireDevis $affaires)
    {
    }

    /**
     * Refuse (422) un passage que la machine à états n'autorise pas.
     *
     * @param Devis $devis
     * @param string $vers Nom du statut visé.
     * @return void
     *
     * @throws ValidationException Si le passage n'est pas permis.
     */
    public function verifier(Devis $devis, string $vers): void
    {
        $depuis = (string) $devis->statut;
        $connus = DevisState::getStateMapping();

        if (! $connus->has($depuis) || ! $connus->has($vers)
            || ! DevisState::config()->isTransitionAllowed($depuis, $vers)) {
            throw ValidationException::withMessages([
                'statut' => sprintf(
                    'Un devis « %s » ne peut pas passer à « %s ».',
                    StatutsDevis::libelle($depuis),
                    StatutsDevis::libelle($vers)
                ),
            ]);
        }
    }

    /**
     * @param Devis $devis
     * @param string $vers Nom du statut visé.
     * @param User|null $auteur
     * @param array{motif?: string|null, preuve_acceptation?: string|null} $donnees
     * @return Devis
     *
     * @throws ValidationException Si le passage n'est pas permis.
     */
    public function appliquer(Devis $devis, string $vers, ?User $auteur, array $donnees = []): Devis
    {
        $this->verifier($devis, $vers);
        $depuis = (string) $devis->statut;

        DB::transaction(function () use ($devis, $depuis, $vers, $auteur, $donnees): void {
            if ($vers === 'envoye') {
                $devis->recalculerTotaux();
                $devis->forceFill(['envoye_le' => now()]);
            }
            if ($vers === 'accepte') {
                $devis->forceFill([
                    'accepte_le' => now(),
                    'preuve_acceptation' => $donnees['preuve_acceptation'] ?? null,
                ]);
            }

            $devis->forceFill(['statut' => $vers])->save();

            activity()
                ->performedOn($devis)
                ->causedBy($auteur)
                ->event('statut')
                ->withProperties(['de' => $depuis, 'vers' => $vers, 'motif' => $donnees['motif'] ?? null])
                ->log(sprintf(
                    'Devis %s : %s → %s.',
                    $devis->numero,
                    StatutsDevis::libelle($depuis),
                    StatutsDevis::libelle($vers)
                ));

            $this->affaires->appliquer($devis, $vers, $auteur);
        });

        return $devis;
    }
}
