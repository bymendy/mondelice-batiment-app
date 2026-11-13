<?php

namespace App\Services\Crm;

use App\Models\EtapePipeline;
use App\Models\Motif;
use App\Models\Opportunite;
use App\Models\OpportuniteEtape;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changement d'etape d'une affaire a la main (glisser-deposer du Pipeline,
 * ou bouton de la fiche).
 *
 * Regles :
 *  - les etapes pilotees par le devis (Devis envoye, Negociation, Gagne) ne se
 *    choisissent pas a la main : elles suivent le statut du devis ;
 *  - une affaire Gagnee ne se deplace plus a la main ;
 *  - Perdu exige un motif de perte, Sans suite un motif d'ecart de demande ;
 *  - une affaire ouverte garde toujours sa prochaine action (texte + date) ;
 *  - les retours en arriere sont permis, signales dans l'historique ;
 *  - chaque changement ecrit une ligne dans opportunite_etapes (insertion
 *    seule) et dans le journal d'activite.
 */
class ChangementEtape
{
    /**
     * Etapes qui suivent le statut du devis et non la main de l'utilisateur.
     */
    public const PILOTEES_PAR_LE_DEVIS = ['devis_envoye', 'negociation', 'gagne'];

    /**
     * Vrai si l'etape peut etre choisie a la main.
     *
     * @param EtapePipeline $etape
     * @return bool
     */
    public static function estManuelle(EtapePipeline $etape): bool
    {
        return ! in_array($etape->code, self::PILOTEES_PAR_LE_DEVIS, true);
    }

    /**
     * Deplace l'affaire vers l'etape demandee.
     *
     * @param Opportunite $affaire
     * @param EtapePipeline $vers
     * @param User|null $auteur
     * @param array{motif_perte_id?: int|null, concurrent?: string|null, prochaine_action?: string|null, prochaine_action_le?: string|null} $donnees
     * @return Opportunite
     *
     * @throws ValidationException Si une regle n'est pas respectee.
     */
    public function appliquer(Opportunite $affaire, EtapePipeline $vers, ?User $auteur, array $donnees = []): Opportunite
    {
        $depuis = $affaire->etape;

        if ($depuis->getKey() === $vers->getKey()) {
            $this->refuser('etape_id', 'L\'affaire est déjà à cette étape.');
        }
        if ($depuis->code === 'gagne') {
            $this->refuser('etape_id', 'Une affaire gagnée suit son devis : elle ne se déplace plus à la main.');
        }
        if ($vers->code === 'gagne') {
            $this->refuser('etape_id', '« Gagné » ne se choisit pas à la main : l\'étape change quand le devis est signé.');
        }
        if (! self::estManuelle($vers)) {
            $this->refuser('etape_id', 'Cette étape suit le devis : elle change toute seule quand le devis est envoyé ou discuté.');
        }

        $ouverte = $vers->type === EtapePipeline::TYPE_OUVERTE;
        $motifId = $donnees['motif_perte_id'] ?? null;

        if (! $ouverte) {
            $type = $vers->type === EtapePipeline::TYPE_PERDUE ? Motif::TYPE_PERTE : Motif::TYPE_ECART_DEMANDE;
            $valide = $motifId !== null
                && Motif::query()->whereKey($motifId)->where('type', $type)->where('actif', true)->exists();
            if (! $valide) {
                $this->refuser('motif_perte_id', $vers->type === EtapePipeline::TYPE_PERDUE
                    ? 'Choisissez le motif de perte.'
                    : 'Choisissez le motif pour lequel la demande est classée sans suite.');
            }
        }

        $action = array_key_exists('prochaine_action', $donnees) ? $donnees['prochaine_action'] : $affaire->prochaine_action;
        $le = array_key_exists('prochaine_action_le', $donnees) ? $donnees['prochaine_action_le'] : $affaire->prochaine_action_le;
        if ($ouverte && (blank($action) || blank($le))) {
            $this->refuser('prochaine_action', 'Indiquez la prochaine action et sa date pour une affaire ouverte.');
        }

        $retourArriere = $vers->ordre < $depuis->ordre
            || ($depuis->type !== EtapePipeline::TYPE_OUVERTE && $ouverte);

        DB::transaction(function () use ($affaire, $depuis, $vers, $auteur, $donnees, $ouverte, $motifId, $action, $le, $retourArriere): void {
            $affaire->etape_id = $vers->getKey();
            $affaire->probabilite = $vers->probabiliteFixe() ?? $vers->probabilite_defaut;

            if ($ouverte) {
                $affaire->motif_perte_id = null;
                $affaire->perdue_le = null;
                $affaire->prochaine_action = $action;
                $affaire->prochaine_action_le = $le;
            } else {
                $affaire->motif_perte_id = $motifId;
                $affaire->perdue_le = now();
                $affaire->prochaine_action = null;
                $affaire->prochaine_action_le = null;
                if (array_key_exists('concurrent', $donnees)) {
                    $affaire->concurrent = $donnees['concurrent'];
                }
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
                ->withProperties(['de' => $depuis->code, 'vers' => $vers->code, 'retour_arriere' => $retourArriere])
                ->log(sprintf('Affaire « %s » : %s → %s.', $affaire->titre, $depuis->nom, $vers->nom));
        });

        return $affaire;
    }

    /**
     * @param string $champ
     * @param string $message
     * @return never
     *
     * @throws ValidationException
     */
    private function refuser(string $champ, string $message): never
    {
        throw ValidationException::withMessages([$champ => $message]);
    }
}
