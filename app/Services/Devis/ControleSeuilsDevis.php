<?php

namespace App\Services\Devis;

use App\Enums\TypeSeuil;
use App\Exceptions\RefusSeuilDevisException;
use App\Models\DerogationDevis;
use App\Models\Devis;
use App\Models\Parametre;
use App\Models\SeuilTarifaire;
use App\Models\User;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Contrôle d'un devis contre les seuils de la Direction (lot D4b, décisions du
 * 18 novembre 2026) : marge globale minimum et remise maximum.
 *
 * Pendant la rédaction :
 *  - une écriture qui fait passer une marge calculable sous le seuil est
 *    refusée (422) et rien n'est enregistré ;
 *  - une marge incomplète (ligne sans prix de revient) est acceptée ;
 *  - un devis déjà sous le seuil peut être amélioré, pas aggravé ;
 *  - même règle pour la remise maximum.
 * À l'envoi : la marge doit être complète et au-dessus du seuil, la remise
 * sous son maximum.
 *
 * Sans seuil actif, rien n'est contrôlé. La Direction (`parametres.seuils`)
 * n'est jamais bloquée : ses écritures sous le seuil sont seulement tracées.
 * Chaque refus est tracé dans le journal, hors de la transaction annulée.
 */
final class ControleSeuilsDevis
{
    private const EPSILON = 0.000001;

    /**
     * La Direction n'est jamais bloquée par les seuils qu'elle fixe.
     *
     * @param User|null $utilisateur
     * @return bool
     */
    public static function exempte(?User $utilisateur): bool
    {
        return $utilisateur !== null && $utilisateur->can('parametres.seuils');
    }

    /**
     * Exécute une écriture sur le devis dans une transaction, puis la contrôle.
     *
     * @param Devis $devis
     * @param User|null $auteur
     * @param Closure $ecriture L'écriture ; elle recalcule les totaux du devis.
     * @return mixed Ce que renvoie l'écriture.
     *
     * @throws HttpResponseException Réponse 422 si un seuil n'est pas respecté.
     */
    public function executer(Devis $devis, ?User $auteur, Closure $ecriture): mixed
    {
        $seuils = $this->seuils($devis);
        if ($seuils['marge'] === null && $seuils['remise'] === null) {
            return DB::transaction(fn () => $ecriture());
        }

        $exempte = self::exempte($auteur);
        $avant = $this->mesurer($devis);
        $refus = null;

        try {
            $resultat = DB::transaction(function () use ($devis, $ecriture, $avant, $seuils, $exempte, &$refus) {
                $resultat = $ecriture();
                $refus = $this->refus($avant, $this->mesurer($devis), $seuils);
                if ($refus !== null && ! $exempte) {
                    throw new RefusSeuilDevisException($refus);
                }

                return $resultat;
            });
        } catch (RefusSeuilDevisException $exception) {
            $this->journaliser($devis, $auteur, 'refus_seuil', $exception->refus, 'ecriture');

            throw $this->reponse($exception->refus, $auteur);
        }

        if ($refus !== null) {
            $this->journaliser($devis, $auteur, 'seuil_direction', $refus, 'ecriture');
        }

        return $resultat;
    }

    /**
     * Contrôle le devis juste avant son envoi.
     *
     * @param Devis $devis
     * @param User|null $auteur
     * @return void
     *
     * @throws HttpResponseException Réponse 422 : `motif` vaut `marge_incomplete`,
     *                               `marge_sous_le_seuil` ou `remise_au_dessus_du_maximum`.
     */
    public function verifierAvantEnvoi(Devis $devis, ?User $auteur): void
    {
        $seuils = $this->seuils($devis);
        if ($seuils['marge'] === null && $seuils['remise'] === null) {
            return;
        }

        $mesure = $this->mesurer($devis);
        $refus = $this->refus(null, $mesure, $seuils);

        if (self::exempte($auteur)) {
            if ($refus !== null) {
                $this->journaliser($devis, $auteur, 'seuil_direction', $refus, 'envoi');
            }

            return;
        }

        if ($seuils['marge'] !== null && ! $mesure['complete']) {
            $lignes = $devis->lignes()->whereNull('prix_revient_unitaire')->get(['id', 'libelle']);
            $message = sprintf(
                'La marge du devis est incomplète : %d ligne(s) sans prix de revient. '
                . 'Complétez-les avant l\'envoi, ou demandez à la Direction.',
                $lignes->count()
            );

            throw new HttpResponseException(response()->json([
                'message' => $message,
                'errors' => ['seuil' => [$message]],
                'motif' => 'marge_incomplete',
                'lignes_sans_revient' => $lignes->map(fn ($ligne): array => [
                    'id' => $ligne->id,
                    'libelle' => $ligne->libelle,
                ])->values()->all(),
            ], 422));
        }

        if ($refus !== null) {
            $this->journaliser($devis, $auteur, 'refus_seuil', $refus, 'envoi');

            throw $this->reponse($refus, $auteur);
        }
    }

    /**
     * Indicateur coloré de la marge du devis. La couleur est toujours
     * accompagnée d'un texte.
     *
     * @param array<string, mixed> $marge Bloc `marge` de CalculTotauxDevis.
     * @return array{couleur: string, libelle: string, seuil: float|null, zone: float}
     */
    public function indicateur(array $marge): array
    {
        $seuil = $this->seuils()['marge'];
        $zone = (float) Parametre::lire('marge_zone_orange_points');
        $taux = $marge['taux_marge_pct'] ?? null;

        [$couleur, $libelle] = match (true) {
            $seuil === null => ['gris', 'Aucun seuil de marge défini'],
            $taux === null => ['gris', 'Marge incomplète'],
            (float) $taux < $seuil - self::EPSILON => ['rouge', 'Marge sous le minimum'],
            (float) $taux < $seuil + $zone - self::EPSILON => ['orange', 'Marge proche du minimum'],
            default => ['vert', 'Marge conforme'],
        };

        return ['couleur' => $couleur, 'libelle' => $libelle, 'seuil' => $seuil, 'zone' => $zone];
    }

    /**
     * Seuils actifs du devis pour l'entreprise courante.
     *
     * @return array{marge: float|null, remise: float|null}
     */
    private function seuils(?Devis $devis = null): array
    {
        $actifs = SeuilTarifaire::actifsParType();
        $marge = $actifs[TypeSeuil::MargeGlobaleDevis->value] ?? null;
        $remise = $actifs[TypeSeuil::RemiseMaximum->value] ?? null;

        // Une dérogation acceptée déplace la limite de ce devis (lot D4c) : elle ne
        // peut que l'assouplir, et ne crée pas de contrôle là où il n'y a pas de seuil.
        if ($devis !== null && ($marge !== null || $remise !== null)) {
            $accordees = DerogationDevis::accordeesPour($devis);
            if ($marge !== null && isset($accordees[TypeSeuil::MargeGlobaleDevis->value])) {
                $marge = min($marge, $accordees[TypeSeuil::MargeGlobaleDevis->value]);
            }
            if ($remise !== null && isset($accordees[TypeSeuil::RemiseMaximum->value])) {
                $remise = max($remise, $accordees[TypeSeuil::RemiseMaximum->value]);
            }
        }

        return ['marge' => $marge, 'remise' => $remise];
    }

    /**
     * Marge et remise du devis tel qu'il est en base.
     *
     * @param Devis $devis
     * @return array{marge: float|null, complete: bool, remise: float}
     */
    private function mesurer(Devis $devis): array
    {
        $detail = (new CalculTotauxDevis())->calculer($devis->lignes()->get(), $devis->deductions()->get());
        $brut = (float) $detail['total_ht_brut'];
        $taux = $detail['marge']['taux_marge_pct'];

        return [
            'marge' => $taux === null ? null : (float) $taux,
            'complete' => (bool) $detail['marge']['revient_complet'],
            'remise' => $brut > 0 ? round((float) $detail['total_remises'] / $brut * 100, 2) : 0.0,
        ];
    }

    /**
     * Le seuil non respecté après l'écriture, ou null.
     *
     * @param array{marge: float|null, complete: bool, remise: float}|null $avant Null : contrôle absolu (envoi).
     * @param array{marge: float|null, complete: bool, remise: float} $apres
     * @param array{marge: float|null, remise: float|null} $seuils
     * @return array{motif: string, valeur: float, seuil: float, message: string}|null
     */
    private function refus(?array $avant, array $apres, array $seuils): ?array
    {
        $min = $seuils['marge'];
        if ($min !== null && $apres['marge'] !== null && $apres['marge'] < $min - self::EPSILON) {
            $dejaSous = $avant !== null && $avant['marge'] !== null && $avant['marge'] < $min - self::EPSILON;
            if (! $dejaSous || $apres['marge'] < $avant['marge'] - self::EPSILON) {
                return [
                    'motif' => 'marge_sous_le_seuil',
                    'valeur' => $apres['marge'],
                    'seuil' => $min,
                    'message' => sprintf(
                        'Vous dépassez le seuil fixé par la Direction : la marge du devis serait de %s %%, pour un minimum de %s %%.',
                        self::pourcent($apres['marge']),
                        self::pourcent($min)
                    ),
                ];
            }
        }

        $max = $seuils['remise'];
        if ($max !== null && $apres['remise'] > $max + self::EPSILON) {
            $dejaAuDessus = $avant !== null && $avant['remise'] > $max + self::EPSILON;
            if (! $dejaAuDessus || $apres['remise'] > $avant['remise'] + self::EPSILON) {
                return [
                    'motif' => 'remise_au_dessus_du_maximum',
                    'valeur' => $apres['remise'],
                    'seuil' => $max,
                    'message' => sprintf(
                        'Vous dépassez le seuil fixé par la Direction : les remises atteindraient %s %% du devis, pour un maximum de %s %%.',
                        self::pourcent($apres['remise']),
                        self::pourcent($max)
                    ),
                ];
            }
        }

        return null;
    }

    /**
     * Réponse 422 d'un refus. La marge n'est pas donnée à qui n'a pas le droit de la voir.
     *
     * @param array{motif: string, valeur: float, seuil: float, message: string} $refus
     * @param User|null $auteur
     * @return HttpResponseException
     */
    private function reponse(array $refus, ?User $auteur): HttpResponseException
    {
        $voitValeur = $refus['motif'] !== 'marge_sous_le_seuil' || PresentationDevis::voitMarge($auteur);
        $message = $voitValeur
            ? $refus['message']
            : 'Vous dépassez le seuil de marge fixé par la Direction.';

        return new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => ['seuil' => [$message]],
            'motif' => $refus['motif'],
            'seuil' => $voitValeur ? $refus['seuil'] : null,
            'valeur' => $voitValeur ? $refus['valeur'] : null,
        ], 422));
    }

    /**
     * Trace un refus (`refus_seuil`) ou une écriture de la Direction sous le
     * seuil (`seuil_direction`).
     *
     * @param Devis $devis
     * @param User|null $auteur
     * @param string $evenement
     * @param array{motif: string, valeur: float, seuil: float, message: string} $refus
     * @param string $moment `ecriture` ou `envoi`.
     * @return void
     */
    private function journaliser(Devis $devis, ?User $auteur, string $evenement, array $refus, string $moment): void
    {
        $quoi = $refus['motif'] === 'marge_sous_le_seuil' ? 'marge' : 'remises';
        $borne = $refus['motif'] === 'marge_sous_le_seuil' ? 'minimum' : 'maximum';

        activity()
            ->performedOn($devis)
            ->causedBy($auteur)
            ->event($evenement)
            ->withProperties([
                'motif' => $refus['motif'],
                'valeur' => $refus['valeur'],
                'seuil' => $refus['seuil'],
                'moment' => $moment,
            ])
            ->log(sprintf(
                $evenement === 'refus_seuil'
                    ? 'Devis %s : %s refusé(e), %s à %s %% pour un %s de %s %%.'
                    : 'Devis %s : %s par la Direction, %s à %s %% pour un %s de %s %%.',
                $devis->numero,
                $moment === 'envoi' ? 'envoi' : 'écriture',
                $quoi,
                self::pourcent($refus['valeur']),
                $borne,
                self::pourcent($refus['seuil'])
            ));

        // Un refus alerte la Direction et l'Administratif (alertes regroupées, lot D4b).
        if ($evenement === 'refus_seuil') {
            app(AlertesSeuils::class)->signaler($devis, $auteur, $refus, $moment);
        }
    }

    /**
     * 17.5 -> « 17,5 » ; 20.0 -> « 20 ».
     *
     * @param float $valeur
     * @return string
     */
    private static function pourcent(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 2, ',', ''), '0'), ',');
    }
}
