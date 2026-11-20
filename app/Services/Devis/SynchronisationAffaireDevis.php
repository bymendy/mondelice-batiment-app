<?php

namespace App\Services\Devis;

use App\Models\Devis;
use App\Models\EtapePipeline;
use App\Models\Opportunite;
use App\Models\OpportuniteEtape;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fait suivre l'étape de l'affaire à la vie du devis.
 *
 * À la création du devis (décision du 18 novembre 2026) :
 *  - devis sans affaire -> une affaire est créée à « Devis en cours » ;
 *  - devis sur une affaire -> l'affaire passe à « Devis en cours ».
 *
 * Au changement de statut (décision du 13 novembre 2026) :
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
     * Délai, en jours, de la prochaine action posée automatiquement.
     */
    private const DELAI_PROCHAINE_ACTION = 3;

    /**
     * Rattache le devis qui vient d'être créé (ou rattaché) à son affaire.
     * Un avenant ne crée pas d'affaire et n'en déplace pas.
     *
     * @param Devis $devis
     * @param User|null $auteur
     * @return void
     */
    public function aLaCreation(Devis $devis, ?User $auteur): void
    {
        if ($devis->devis_parent_id !== null) {
            return;
        }

        $vers = EtapePipeline::query()->where('code', 'devis_en_cours')->first();
        if ($vers === null) {
            return;
        }

        if ($devis->opportunite_id === null) {
            $this->creerAffaire($devis, $vers, $auteur);

            return;
        }

        $affaire = Opportunite::query()->with('etape')->find($devis->opportunite_id);
        if ($affaire === null) {
            return;
        }

        $this->deplacer($affaire, $vers, $devis, $auteur, 'Finaliser le devis ' . $devis->numero);
    }

    /**
     * @param Devis $devis
     * @param string $statut Le nouveau statut du devis.
     * @param User|null $auteur
     * @return void
     */
    public function appliquer(Devis $devis, string $statut, ?User $auteur): void
    {
        $code = self::ETAPE_PAR_STATUT[$statut] ?? null;
        if ($code === null || $devis->opportunite_id === null || $devis->devis_parent_id !== null) {
            return;
        }

        $affaire = Opportunite::query()->with('etape')->find($devis->opportunite_id);
        $vers = EtapePipeline::query()->where('code', $code)->first();
        if ($affaire === null || $vers === null) {
            return;
        }

        $this->deplacer($affaire, $vers, $devis, $auteur, 'Suivre le devis ' . $devis->numero);
    }

    /**
     * Crée l'affaire d'un devis saisi sans affaire, directement à « Devis en cours ».
     *
     * @param Devis $devis
     * @param EtapePipeline $etape
     * @param User|null $auteur
     * @return void
     */
    private function creerAffaire(Devis $devis, EtapePipeline $etape, ?User $auteur): void
    {
        DB::transaction(function () use ($devis, $etape, $auteur): void {
            $titre = trim((string) $devis->titre);

            $affaire = Opportunite::create([
                'client_id' => $devis->client_id,
                'adresse_chantier_id' => $devis->adresse_chantier_id,
                'titre' => $titre !== '' ? $titre : 'Devis ' . $devis->numero,
                'etape_id' => $etape->getKey(),
                'probabilite' => $etape->probabiliteFixe() ?? $etape->probabilite_defaut,
                'commercial_id' => $auteur?->getKey() ?? $devis->auteur_id,
                'prochaine_action' => 'Finaliser et envoyer le devis ' . $devis->numero,
                'prochaine_action_le' => now()->addDays(self::DELAI_PROCHAINE_ACTION)->toDateString(),
            ]);

            $devis->forceFill(['opportunite_id' => $affaire->getKey()])->save();

            OpportuniteEtape::create([
                'opportunite_id' => $affaire->getKey(),
                'etape_depuis_id' => null,
                'etape_vers_id' => $etape->getKey(),
                'change_le' => now(),
                'auteur_id' => $auteur?->getKey(),
                'triggered_by' => 'human',
                'devis_id' => $devis->getKey(),
                'retour_arriere' => false,
            ]);

            activity()
                ->performedOn($affaire)
                ->causedBy($auteur)
                ->event('created')
                ->withProperties(['vers' => $etape->code, 'devis' => $devis->numero, 'creee_par_devis' => true])
                ->log(sprintf('Affaire « %s » créée par le devis %s.', $affaire->titre, $devis->numero));
        });
    }

    /**
     * Déplace l'affaire vers l'étape visée, si les règles le permettent.
     *
     * @param Opportunite $affaire Avec son étape chargée.
     * @param EtapePipeline $vers
     * @param Devis $devis
     * @param User|null $auteur
     * @param string $actionReouverture Prochaine action posée si l'affaire était clôturée.
     * @return void
     */
    private function deplacer(Opportunite $affaire, EtapePipeline $vers, Devis $devis, ?User $auteur, string $actionReouverture): void
    {
        $depuis = $affaire->etape;
        if ($depuis === null || $depuis->getKey() === $vers->getKey() || $depuis->code === 'gagne') {
            return;
        }

        $ouverteAvant = $depuis->type === EtapePipeline::TYPE_OUVERTE;
        if ($ouverteAvant && $vers->ordre <= $depuis->ordre) {
            return;
        }

        $gagne = $vers->code === 'gagne';
        $retourArriere = $vers->ordre < $depuis->ordre
            || (! $ouverteAvant && $vers->type === EtapePipeline::TYPE_OUVERTE);

        DB::transaction(function () use ($affaire, $depuis, $vers, $devis, $auteur, $gagne, $ouverteAvant, $retourArriere, $actionReouverture): void {
            $affaire->etape_id = $vers->getKey();
            $affaire->probabilite = $vers->probabiliteFixe() ?? $vers->probabilite_defaut;
            $affaire->motif_perte_id = null;
            $affaire->perdue_le = null;

            if ($gagne) {
                $affaire->gagnee_le = now();
                $affaire->prochaine_action = null;
                $affaire->prochaine_action_le = null;
            } elseif (! $ouverteAvant) {
                $affaire->prochaine_action = $actionReouverture;
                $affaire->prochaine_action_le = now()->addDays(self::DELAI_PROCHAINE_ACTION)->toDateString();
            }
            $affaire->save();

            OpportuniteEtape::create([
                'opportunite_id' => $affaire->getKey(),
                'etape_depuis_id' => $depuis->getKey(),
                'etape_vers_id' => $vers->getKey(),
                'change_le' => now(),
                'auteur_id' => $auteur?->getKey(),
                'triggered_by' => 'human',
                'devis_id' => $devis->getKey(),
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
