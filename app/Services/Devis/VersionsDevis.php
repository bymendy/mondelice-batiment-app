<?php

namespace App\Services\Devis;

use App\Models\DeductionDevis;
use App\Models\DerogationDevis;
use App\Models\Devis;
use App\Models\DevisParametres;
use App\Models\DevisTauxHoraire;
use App\Models\LigneDevis;
use App\Models\LigneDevisComposant;
use App\Models\LotDevis;
use App\Models\User;
use App\States\Devis\DevisState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versions d'un devis (lot V1, décisions du 27 novembre 2026).
 *
 * Avant la signature, un changement demandé par le client se fait dans une
 * nouvelle version : une copie complète du devis, de nouveau modifiable.
 * Après la signature, c'est un avenant.
 *
 *  - Les versions d'un même devis forment un groupe. Le premier devis garde
 *    son numéro ; les suivantes portent ce numéro suivi de `-V2`, `-V3`...
 *    Un rang n'est jamais réutilisé.
 *  - Une version se crée depuis n'importe quelle version du groupe, quel que
 *    soit son statut, tant qu'aucune version n'est acceptée.
 *  - Recopié à l'identique : lots, lignes et sous-détails, remises,
 *    paramètres et taux horaires du devis, client, adresse, affaire, portée,
 *    auteur (celui qui crée la version est dans le journal).
 *    Les prix ne changent pas. Pas recopié : statut, dates d'envoi, preuve,
 *    journal, dérogations.
 *  - Mode `remplace` : la version copiée passe à « Non retenue ». Mode
 *    `en_plus` : les deux restent proposées au client.
 *  - Quand une version est acceptée, les autres versions encore ouvertes
 *    passent à « Non retenue ».
 *
 * Une version ne crée pas d'affaire et ne déplace pas celle du devis.
 */
class VersionsDevis
{
    public const MODE_REMPLACE = 'remplace';

    public const MODE_EN_PLUS = 'en_plus';

    public const MOTIF_REMPLACE = 'remplace';

    public const MOTIF_AUTRE_ACCEPTEE = 'autre_version_acceptee';

    /**
     * Identifiant du premier devis du groupe.
     *
     * @param Devis $devis
     * @return int
     */
    public static function racine(Devis $devis): int
    {
        return (int) ($devis->version_origine_id ?? $devis->getKey());
    }

    /**
     * Toutes les versions du groupe, de la première à la dernière.
     *
     * @param Devis $devis
     * @return Collection<int, Devis>
     */
    public static function groupe(Devis $devis): Collection
    {
        $racine = self::racine($devis);

        return Devis::query()
            ->where(fn ($q) => $q->whereKey($racine)->orWhere('version_origine_id', $racine))
            ->orderByRaw('coalesce(rang_version, 1)')
            ->orderBy('id')
            ->get();
    }

    /**
     * Pourquoi on ne peut pas créer de version depuis ce devis, ou null si on peut.
     *
     * @param Devis $devis
     * @return string|null
     */
    public static function refus(Devis $devis): ?string
    {
        if ($devis->devis_parent_id !== null) {
            return 'Un avenant n\'a pas de version : modifiez-le tant qu\'il n\'est pas envoyé, ou créez un autre avenant.';
        }
        if (self::groupe($devis)->contains(fn (Devis $version): bool => (string) $version->statut === 'accepte')) {
            return 'Une version de ce devis est acceptée : un changement se fait maintenant par un avenant.';
        }

        return null;
    }

    /**
     * Crée une nouvelle version à partir d'une version existante.
     *
     * @param Devis $source Version copiée.
     * @param User $auteur
     * @param string $mode `remplace` ou `en_plus`.
     * @param array<string, mixed> $donnees Facultatif : titre, notes, validite_jours.
     * @return Devis La nouvelle version, en préparation.
     *
     * @throws ValidationException Si le devis ne peut pas recevoir de version.
     */
    public function creer(Devis $source, User $auteur, string $mode = self::MODE_REMPLACE, array $donnees = []): Devis
    {
        return DB::transaction(function () use ($source, $auteur, $mode, $donnees): Devis {
            $racineId = self::racine($source);
            // Un seul rang à la fois par groupe, même si deux demandes arrivent ensemble.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$source->tenant_id . '|versions|' . $racineId]);

            $refus = self::refus($source);
            if ($refus !== null) {
                throw ValidationException::withMessages(['devis' => $refus]);
            }

            $racine = Devis::withTrashed()->find($racineId) ?? $source;
            // Les versions supprimées comptent aussi : un rang n'est jamais réutilisé.
            $dernier = (int) Devis::withTrashed()
                ->where(fn ($q) => $q->whereKey($racineId)->orWhere('version_origine_id', $racineId))
                ->selectRaw('max(coalesce(rang_version, 1)) as dernier')
                ->value('dernier');
            $rang = max($dernier, 1) + 1;

            $version = new Devis([
                'client_id' => $source->client_id,
                'opportunite_id' => $source->opportunite_id,
                'adresse_chantier_id' => $source->adresse_chantier_id,
                'titre' => ($donnees['titre'] ?? null) ?: $source->titre,
                'portee' => $source->portee,
                // La version reste dans le dossier de l'auteur du devis : il continue de la voir si elle est privée.
                'auteur_id' => $source->auteur_id ?? $auteur->getKey(),
                'date_devis' => now()->toDateString(),
                'validite_jours' => $donnees['validite_jours'] ?? $source->validite_jours ?? 30,
                'notes' => array_key_exists('notes', $donnees) ? $donnees['notes'] : $source->notes,
            ]);
            $version->date_expiration = $version->date_devis->copy()->addDays($version->validite_jours);
            $version->forceFill([
                'version_origine_id' => $racineId,
                'rang_version' => $rang,
                'numero' => ($racine->numero ?? 'DEVIS-' . $racineId) . '-V' . $rang,
            ]);
            $version->save();

            $this->copierContenu($source, $version);
            $version->recalculerTotaux();

            activity()
                ->performedOn($version)
                ->causedBy($auteur)
                ->event('created')
                ->withProperties([
                    'version_de' => $source->getKey(),
                    'numero_source' => $source->numero,
                    'rang_version' => $rang,
                    'mode' => $mode,
                    'cree_par' => $auteur->getKey(),
                ])
                ->log(sprintf('Version %s créée à partir du devis %s.', $version->numero, $source->numero));

            // Une version déjà décidée (refusée, expirée...) garde son statut : il dit ce qui s'est passé.
            if ($mode === self::MODE_REMPLACE && $this->estOuverte($source)) {
                $this->marquerNonRetenue($source, self::MOTIF_REMPLACE, $version, $auteur);
            }

            return $version->refresh();
        });
    }

    /**
     * Une version vient d'être acceptée : les autres versions encore ouvertes
     * du groupe passent à « Non retenue ».
     *
     * @param Devis $acceptee
     * @param User|null $auteur
     * @return int Nombre de versions fermées.
     */
    public function fermerAutres(Devis $acceptee, ?User $auteur): int
    {
        if ($acceptee->devis_parent_id !== null) {
            return 0;
        }

        $fermees = 0;
        foreach (self::groupe($acceptee) as $version) {
            if ($version->getKey() === $acceptee->getKey() || ! $this->estOuverte($version)) {
                continue;
            }
            $this->marquerNonRetenue($version, self::MOTIF_AUTRE_ACCEPTEE, $acceptee, $auteur);
            $fermees++;
        }

        return $fermees;
    }

    /**
     * Ce que la fiche d'un devis dit de ses versions.
     *
     * @param Devis $devis
     * @param User|null $utilisateur
     * @return array<string, mixed>
     */
    public static function pour(Devis $devis, ?User $utilisateur): array
    {
        if ($devis->devis_parent_id !== null) {
            return [];
        }

        $groupe = self::groupe($devis);
        $visibles = $groupe->filter(fn (Devis $version): bool => $utilisateur === null || AccesDevis::peutVoir($utilisateur, $version));
        $acceptee = $groupe->contains(fn (Devis $version): bool => (string) $version->statut === 'accepte');
        $remplacant = $devis->non_retenu_par_id !== null ? $groupe->firstWhere('id', $devis->non_retenu_par_id) : null;

        return [
            'version' => [
                'rang' => (int) ($devis->rang_version ?? 1),
                'origine_id' => self::racine($devis),
                'nombre_de_versions' => $groupe->count(),
                'non_retenu_motif' => $devis->non_retenu_motif,
                'non_retenu_au_profit_de' => $remplacant === null ? null : ['id' => $remplacant->getKey(), 'numero' => $remplacant->numero],
                'non_retenu_le' => $devis->non_retenu_le?->toIso8601String(),
            ],
            'versions' => $visibles->map(fn (Devis $version): array => [
                'id' => $version->getKey(),
                'numero' => $version->numero,
                'rang' => (int) ($version->rang_version ?? 1),
                'titre' => $version->titre,
                'statut' => (string) $version->statut,
                'statut_libelle' => StatutsDevis::libelle((string) $version->statut),
                'macro_etat' => StatutsDevis::macroEtat((string) $version->statut),
                'date_devis' => $version->date_devis?->toDateString(),
                'total_ht' => (float) $version->total_ht,
                'total_ttc' => (float) $version->total_ttc,
                'courante' => $version->getKey() === $devis->getKey(),
            ])->values()->all(),
            'peut_creer_version' => ! $acceptee,
        ];
    }

    /**
     * En cours de rédaction ou en attente de réponse du client.
     *
     * @param Devis $devis
     * @return bool
     */
    private function estOuverte(Devis $devis): bool
    {
        return in_array((string) $devis->statut, array_merge(StatutsDevis::BROUILLON, StatutsDevis::ENVOYE), true);
    }

    /**
     * @param Devis $devis Version qui n'est pas retenue.
     * @param string $motif `remplace` ou `autre_version_acceptee`.
     * @param Devis $auProfitDe Version qui la remplace ou qui a été acceptée.
     * @param User|null $auteur
     * @return void
     */
    private function marquerNonRetenue(Devis $devis, string $motif, Devis $auProfitDe, ?User $auteur): void
    {
        $depuis = (string) $devis->statut;
        if (! DevisState::config()->isTransitionAllowed($depuis, 'non_retenu')) {
            return;
        }

        $devis->forceFill([
            'statut' => 'non_retenu',
            'non_retenu_motif' => $motif,
            'non_retenu_par_id' => $auProfitDe->getKey(),
            'non_retenu_le' => now(),
        ])->save();

        // Une demande de dérogation en attente n'a plus d'objet.
        DerogationDevis::query()
            ->where('devis_id', $devis->getKey())
            ->where('statut', DerogationDevis::EN_ATTENTE)
            ->update(['statut' => DerogationDevis::ANNULEE]);

        $texte = $motif === self::MOTIF_REMPLACE
            ? sprintf('remplacé par la version %s', $auProfitDe->numero)
            : sprintf('la version %s a été acceptée', $auProfitDe->numero);

        activity()
            ->performedOn($devis)
            ->causedBy($auteur)
            ->event('statut')
            ->withProperties(['de' => $depuis, 'vers' => 'non_retenu', 'motif' => $motif, 'au_profit_de' => $auProfitDe->getKey()])
            ->log(sprintf('Devis %s : %s → Non retenue (%s).', $devis->numero, StatutsDevis::libelle($depuis), $texte));
    }

    /**
     * Copie à l'identique le contenu d'un devis dans un autre : paramètres,
     * taux horaires, lots, lignes et sous-détails, remises.
     *
     * @param Devis $source
     * @param Devis $cible Devis vide, déjà enregistré.
     * @return void
     */
    private function copierContenu(Devis $source, Devis $cible): void
    {
        $parametres = DevisParametres::query()->where('devis_id', $source->getKey())->first();
        if ($parametres === null) {
            // Ancien devis sans paramètres propres : la version prend ceux de l'entreprise.
            app(ParametresDevis::class)->creer($cible);
        } else {
            $copie = $parametres->replicate();
            $copie->devis_id = $cible->getKey();
            $copie->save();
            foreach (DevisTauxHoraire::query()->where('devis_id', $source->getKey())->orderBy('id')->get() as $taux) {
                $copie = $taux->replicate();
                $copie->devis_id = $cible->getKey();
                $copie->save();
            }
        }

        // Lots et emplacements : on garde la correspondance entre anciens et nouveaux identifiants.
        $lots = [];
        foreach (LotDevis::query()->where('devis_id', $source->getKey())->orderBy('id')->get() as $lot) {
            $copie = $lot->replicate();
            $copie->devis_id = $cible->getKey();
            $copie->save();
            $lots[(int) $lot->getKey()] = (int) $copie->getKey();
        }

        $lignes = LigneDevis::query()->where('devis_id', $source->getKey())->with('composants')->orderBy('id')->get();
        foreach ($lignes as $ligne) {
            $copie = $ligne->replicate();
            $copie->devis_id = $cible->getKey();
            $copie->lot_id = $ligne->lot_id !== null ? ($lots[(int) $ligne->lot_id] ?? null) : null;
            $copie->emplacement_id = $ligne->emplacement_id !== null ? ($lots[(int) $ligne->emplacement_id] ?? null) : null;
            $copie->ligne_origine_id = $ligne->getKey();
            $copie->save();

            foreach ($ligne->composants as $composant) {
                /** @var LigneDevisComposant $composant */
                $copieComposant = $composant->replicate();
                $copieComposant->ligne_devis_id = $copie->getKey();
                $copieComposant->save();
            }
        }

        foreach (DeductionDevis::query()->where('devis_id', $source->getKey())->orderBy('id')->get() as $remise) {
            $copie = $remise->replicate();
            $copie->devis_id = $cible->getKey();
            $copie->lot_id = $remise->lot_id !== null ? ($lots[(int) $remise->lot_id] ?? null) : null;
            $copie->save();
        }
    }
}
