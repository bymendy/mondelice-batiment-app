<?php

namespace App\Services\Devis;

use App\Models\Devis;
use App\Models\EtapePipeline;
use App\Models\Opportunite;
use App\Models\OpportuniteEtape;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fait suivre l'étape de l'affaire au statut du devis (décision du 13 novembre
 * 2026) :
 *  - devis envoyé    -> « Devis envoyé » ;
 *  - négociation     -> « Négociation » ;
 *  - devis accepté   -> « Gagné ».
 * Un devis refusé, expiré ou annulé ne déplace pas l'affaire : le commercial la
 * classe lui-même (Perdu avec motif, Sans suite) ou refait un devis.
 *
 * Une affaire ouverte ne recule jamais sous l'effet d'un devis ; une affaire
 * déjà gagnée ne bouge plus ; une affaire clôturée (perdue, sans suite) qui
 * reçoit un devis est rouverte avec une prochaine action. Chaque déplacement
 * s'écrit dans l'historique des étapes et dans le journal.
 */
class SynchronisationAffaireDevis
{
    /**
     * Étape visée selon le statut du devis.
     *
     * @var array<string, string>
     */
    private const ETAPE_PAR_STATUT = [
        'envoye' => 'devis_envoye',
        'negociation' => 'negociation',
        'accepte' => 'gagne',
    ];

    /**
     * @param Devis $devis
     * @param string $statut Le nouveau statut du devis.
     * @param User|null $auteur
     * @return void
     */
    public function appliquer(Devis $devis, string $statut, ?User $auteur): void
    {
        $code = self::ETAPE_PAR_STATUT[$statut] ?? null;
        if ($code === null || $devis->opportunite_id === null) {
            return;
        }

        $affaire = Opportunite::query()->with('etape')->find($devis->opportunite_id);
        $vers = EtapePipeline::query()->where('code', $code)->first();
        if ($affaire === null || $vers === null) {
            return;
        }

        $depuis = $affaire->etape;
        if ($depuis->getKey() === $vers->getKey() || $depuis->code === 'gagne') {
            return;
        }

        $ouverteAvant = $depuis->type === EtapePipeline::TYPE_OUVERTE;
        if ($ouverteAvant && $vers->ordre <= $depuis->ordre) {
            return;
        }

        $gagne = $vers->code === 'gagne';
        $retourArriere = $vers->ordre < $depuis->ordre
            || (! $ouverteAvant && $vers->type === EtapePipeline::TYPE_OUVERTE);

        DB::transaction(function () use ($affaire, $depuis, $vers, $devis, $auteur, $gagne, $ouverteAvant, $retourArriere): void {
            $affaire->etape_id = $vers->getKey();
            $affaire->probabilite = $vers->probabiliteFixe() ?? $vers->probabilite_defaut;
            $affaire->motif_perte_id = null;
            $affaire->perdue_le = null;

            if ($gagne) {
                $affaire->gagnee_le = now();
                $affaire->prochaine_action = null;
                $affaire->prochaine_action_le = null;
            } elseif (! $ouverteAvant) {
                $affaire->prochaine_action = 'Suivre le devis ' . $devis->numero;
                $affaire->prochaine_action_le = now()->addDays(3)->toDateString();
            }
            $affaire->save();

            OpportuniteEtape::create([
                'opportunite_id' => $affaire->getKey(),
                'etape_depuis_id' => $depuis->getKey(),
                'etape_vers_id' => $vers->getKey(),
                'change_le' => now(),
                'auteur_id' => $auteur?->getKey(),
                'triggered_by' => 'human',
                'retour_arriere' => $retourArriere,
            ]);

            activity()
                ->performedOn($affaire)
                ->causedBy($auteur)
                ->event('etape')
                ->withProperties(['de' => $depuis->code, 'vers' => $vers->code, 'retour_arriere' => $retourArriere, 'devis' => $devis->numero])
                ->log(sprintf('Affaire « %s » : %s → %s (devis %s).', $affaire->titre, $depuis->nom, $vers->nom, $devis->numero));
        });
    }
}
