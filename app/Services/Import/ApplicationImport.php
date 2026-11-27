<?php

namespace App\Services\Import;

use App\Models\ArticleComposant;
use App\Models\Fournisseur;
use App\Models\Fourniture;
use App\Models\ImportFournitures;
use App\Models\ImportFournituresLigne;
use App\Models\User;
use App\Services\Bibliotheque\ArticlesSousSeuil;
use App\Services\Bibliotheque\SynchronisationFournitures;
use App\Services\Bibliotheque\TarifFourniture;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Application d'un import de catalogue à la Base de fournitures (tâche
 * 3.6 bis, lot F3b). Règles (décisions du 25 novembre 2026) :
 *
 *  - seules les lignes retenues (`creer`, `modifier`) sont appliquées ;
 *  - tout ou rien : une seule transaction ;
 *  - si la base a changé depuis l'analyse, l'application est refusée : il
 *    faut relancer l'analyse (on n'écrase jamais une modification non vue) ;
 *  - les règles de prix sont celles de la Base de fournitures : le tarif
 *    donne l'achat net avec la remise, un prix de vente calculé est
 *    recalculé, un prix de vente saisi ne bouge que si le fichier en donne un ;
 *  - les articles suivent (lien vivant), les devis ne bougent pas ;
 *  - la date de tarif s'inscrit sur les fournitures créées, modifiées et
 *    confirmées (lignes inchangées) ;
 *  - après l'application, le fichier est supprimé et le comparatif gardé.
 */
final class ApplicationImport
{
    /**
     * @param TarifFourniture $tarif
     */
    public function __construct(private readonly TarifFourniture $tarif)
    {
    }

    /**
     * @param ImportFournitures $import Import au statut `analyse`.
     * @param User|null $auteur
     * @return array{creees: int, modifiees: int, dates_confirmees: int, articles_mis_a_jour: int, articles_sous_seuil: array<int, int>}
     *
     * @throws ValidationException Si rien n'est retenu, si la base a changé ou si un prix ne se calcule pas.
     */
    public function appliquer(ImportFournitures $import, ?User $auteur): array
    {
        $fournisseur = Fournisseur::query()->find($import->fournisseur_id);
        if ($fournisseur === null) {
            throw ValidationException::withMessages(['import' => 'Le fournisseur de cet import est archivé.']);
        }

        $retenues = ImportFournituresLigne::query()
            ->where('import_id', $import->getKey())
            ->where('retenue', true)
            ->whereIn('action', ['creer', 'modifier'])
            ->orderBy('numero_ligne')
            ->get();
        $dateTarif = $import->date_tarif?->format('Y-m-d');
        $confirmees = $dateTarif === null ? [] : ImportFournituresLigne::query()
            ->where('import_id', $import->getKey())
            ->where('action', 'inchange')
            ->whereNotNull('fourniture_id')
            ->pluck('fourniture_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($retenues->isEmpty() && $confirmees === []) {
            throw ValidationException::withMessages(['import' => 'Aucune ligne n\'est retenue : il n\'y a rien à appliquer.']);
        }

        $fournitures = Fourniture::query()
            ->where('fournisseur_id', $fournisseur->getKey())
            ->whereIn('id', $retenues->where('action', 'modifier')->pluck('fourniture_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $this->exigerBaseInchangee($import, $retenues, $fournitures);

        @set_time_limit(300);

        $bilan = DB::transaction(function () use ($import, $retenues, $fournitures, $fournisseur, $dateTarif, $confirmees, $auteur): array {
            $creees = 0;
            $modifiees = [];

            foreach ($retenues as $ligne) {
                if ($ligne->action === 'creer') {
                    $this->creer($import, $ligne, $fournisseur, $dateTarif, $auteur);
                    $creees++;
                } elseif ($this->modifier($import, $ligne, $fournitures[$ligne->fourniture_id], $fournisseur, $dateTarif, $auteur)) {
                    $modifiees[] = (int) $ligne->fourniture_id;
                }
            }

            $dates = 0;
            foreach (array_chunk($confirmees, 500) as $paquet) {
                $dates += Fourniture::query()->whereIn('id', $paquet)->update(['date_tarif' => $dateTarif]);
            }

            $articles = [];
            foreach (array_chunk($modifiees, 500) as $paquet) {
                $articles = array_merge($articles, ArticleComposant::query()
                    ->where(fn ($q) => $q->whereIn('fourniture_id', $paquet)->orWhereIn('fourniture_eco_id', $paquet)->orWhereIn('fourniture_haut_id', $paquet))
                    ->distinct()->pluck('article_id')->map(static fn ($id): int => (int) $id)->all());
            }
            $articles = array_values(array_unique($articles));

            $bilan = [
                'creees' => $creees,
                'modifiees' => count($modifiees),
                'dates_confirmees' => $dates,
                'articles_mis_a_jour' => count($articles),
                'articles_sous_seuil' => $articles === [] ? [] : ArticlesSousSeuil::ids($articles),
            ];

            $import->update([
                'statut' => 'applique',
                'applique_le' => now(),
                'applique_par' => $auteur?->getKey(),
                'bilan' => $bilan,
            ]);

            activity()->performedOn($import)->causedBy($auteur)->event('import_fournitures_applique')
                ->withProperties(['fournisseur_id' => $fournisseur->getKey(), 'fichier' => $import->nom_fichier, 'mode' => $import->mode, 'date_tarif' => $dateTarif, 'bilan' => $bilan])
                ->log(sprintf(
                    'Catalogue « %s » appliqué pour %s : %d fourniture(s) créée(s), %d modifiée(s).',
                    $import->nom_fichier,
                    $fournisseur->nom,
                    $bilan['creees'],
                    $bilan['modifiees']
                ));

            return $bilan;
        });

        // Le comparatif reste comme trace ; le fichier n'a plus d'utilité.
        Storage::disk(ImportCatalogue::DISQUE)->delete($import->chemin);

        return $bilan;
    }

    /**
     * Refuse l'application si une fourniture a changé depuis l'analyse, ou si
     * une référence à créer existe maintenant.
     *
     * @param ImportFournitures $import
     * @param Collection<int, ImportFournituresLigne> $retenues
     * @param Collection<int, Fourniture> $fournitures Fournitures à modifier, par identifiant.
     * @return void
     *
     * @throws ValidationException
     */
    private function exigerBaseInchangee(ImportFournitures $import, Collection $retenues, Collection $fournitures): void
    {
        $aCreer = $retenues->where('action', 'creer')->pluck('reference')->all();
        $dejaLa = [];
        foreach (array_chunk($aCreer, 500) as $paquet) {
            $dejaLa = array_merge($dejaLa, Fourniture::query()->where('fournisseur_id', $import->fournisseur_id)
                ->whereIn('reference', $paquet)->pluck('reference')->all());
        }
        $dejaLa = array_flip($dejaLa);

        $perimees = [];
        foreach ($retenues as $ligne) {
            if ($ligne->action === 'creer') {
                if (isset($dejaLa[(string) $ligne->reference])) {
                    $perimees[] = $ligne->numero_ligne;
                }

                continue;
            }

            $fourniture = $fournitures[$ligne->fourniture_id] ?? null;
            if ($fourniture === null || ! $this->correspond($fourniture, $ligne->anciennes ?? [])) {
                $perimees[] = $ligne->numero_ligne;
            }
        }

        if ($perimees !== []) {
            throw ValidationException::withMessages(['import' => sprintf(
                'La Base de fournitures a changé depuis l\'analyse (%d ligne(s) concernée(s), dont la ligne %d). Relancez l\'analyse avant d\'appliquer.',
                count($perimees),
                $perimees[0]
            )]);
        }
    }

    /**
     * La fourniture a-t-elle encore les valeurs relevées à l'analyse ?
     *
     * @param Fourniture $fourniture
     * @param array<string, mixed> $anciennes
     * @return bool
     */
    private function correspond(Fourniture $fourniture, array $anciennes): bool
    {
        foreach (['designation', 'unite'] as $champ) {
            if ((string) $fourniture->{$champ} !== (string) ($anciennes[$champ] ?? '')) {
                return false;
            }
        }
        foreach (['prix_tarif_ht', 'prix_achat_ht', 'remise_pct', 'prix_vente_ht'] as $champ) {
            $actuel = $fourniture->{$champ};
            $ancien = $anciennes[$champ] ?? null;
            if (($actuel === null) !== ($ancien === null)) {
                return false;
            }
            if ($actuel !== null && abs((float) $actuel - (float) $ancien) >= 0.00005) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param ImportFournitures $import
     * @param ImportFournituresLigne $ligne
     * @param Fournisseur $fournisseur
     * @param string|null $dateTarif
     * @param User|null $auteur
     * @return void
     */
    private function creer(ImportFournitures $import, ImportFournituresLigne $ligne, Fournisseur $fournisseur, ?string $dateTarif, ?User $auteur): void
    {
        $prix = $this->prix($ligne, $ligne->prix_tarif_ht, $ligne->remise_pct, $ligne->prix_achat_ht, $ligne->prix_vente_ht, $fournisseur);

        $valeurs = $prix + [
            'fournisseur_id' => $fournisseur->getKey(),
            'reference' => $ligne->reference,
            'designation' => mb_substr((string) $ligne->designation, 0, 255),
            'unite' => mb_substr((string) $ligne->unite, 0, 20),
            'prix_tarif_ht' => $ligne->prix_tarif_ht,
            'remise_pct' => $ligne->remise_pct,
            'date_tarif' => $dateTarif,
        ];
        $fourniture = Fourniture::create($valeurs);
        $ligne->forceFill(['fourniture_id' => $fourniture->getKey()])->save();

        activity()->performedOn($fourniture)->causedBy($auteur)->event('fourniture_creee')
            ->withProperties(['apres' => $valeurs, 'import_id' => $import->getKey(), 'ligne' => $ligne->numero_ligne])
            ->log(sprintf('Fourniture « %s » créée par l\'import du catalogue « %s ».', $fourniture->designation, $import->nom_fichier));
    }

    /**
     * @param ImportFournitures $import
     * @param ImportFournituresLigne $ligne
     * @param Fourniture $fourniture
     * @param Fournisseur $fournisseur
     * @param string|null $dateTarif
     * @param User|null $auteur
     * @return bool Vrai si la fourniture a été enregistrée.
     */
    private function modifier(
        ImportFournitures $import,
        ImportFournituresLigne $ligne,
        Fourniture $fourniture,
        Fournisseur $fournisseur,
        ?string $dateTarif,
        ?User $auteur
    ): bool {
        $champs = $ligne->modifications ?? [];
        $change = static fn (string $champ): bool => in_array($champ, $champs, true);

        // Un prix d'achat net importé remplace le prix tarif.
        if ($change('prix_achat_ht')) {
            $tarif = null;
            $achat = $ligne->prix_achat_ht;
        } else {
            $tarif = $change('prix_tarif_ht') ? $ligne->prix_tarif_ht : $fourniture->prix_tarif_ht;
            $tarif = $tarif !== null ? (float) $tarif : null;
            $achat = $tarif === null ? $fourniture->prix_achat_ht : null;
        }
        $remise = $change('remise_pct') ? $ligne->remise_pct : $fourniture->remise_pct;
        // Un prix de vente saisi à la main ne bouge que si le fichier en donne un.
        $vente = $change('prix_vente_ht') ? $ligne->prix_vente_ht : ($fourniture->prix_vente_saisi ? $fourniture->prix_vente_ht : null);

        $valeurs = $this->prix($ligne, $tarif, $remise, $achat, $vente, $fournisseur) + ['prix_tarif_ht' => $tarif, 'remise_pct' => $remise];
        if ($change('designation')) {
            $valeurs['designation'] = mb_substr((string) $ligne->designation, 0, 255);
        }
        if ($change('unite')) {
            $valeurs['unite'] = mb_substr((string) $ligne->unite, 0, 20);
        }
        if ($dateTarif !== null) {
            $valeurs['date_tarif'] = $dateTarif;
        }

        $fourniture->fill($valeurs);
        $apres = $fourniture->getDirty();
        if ($apres === []) {
            return false;
        }
        $avant = array_intersect_key($fourniture->getRawOriginal(), $apres);
        $fourniture->save();
        $articles = SynchronisationFournitures::propager($fourniture);

        activity()->performedOn($fourniture)->causedBy($auteur)->event('fourniture_modifiee')
            ->withProperties([
                'avant' => $avant,
                'apres' => $apres,
                'articles_mis_a_jour' => $articles,
                'import_id' => $import->getKey(),
                'ligne' => $ligne->numero_ligne,
                'ecart_pct' => $ligne->ecart_pct,
                'ecart_valide_a_la_main' => (bool) $ligne->a_valider,
            ])
            ->log(sprintf(
                'Fourniture « %s » modifiée par l\'import du catalogue « %s » (%d article(s) mis à jour).',
                $fourniture->designation,
                $import->nom_fichier,
                $articles
            ));

        return true;
    }

    /**
     * Calcule les prix d'une ligne avec les règles de la Base de fournitures.
     * Une erreur porte le numéro de la ligne du fichier.
     *
     * @param ImportFournituresLigne $ligne
     * @param float|null $tarif
     * @param mixed $remise
     * @param mixed $achat
     * @param mixed $vente
     * @param Fournisseur $fournisseur
     * @return array{prix_achat_ht: float|null, prix_vente_ht: float, prix_vente_saisi: bool}
     *
     * @throws ValidationException
     */
    private function prix(ImportFournituresLigne $ligne, ?float $tarif, mixed $remise, mixed $achat, mixed $vente, Fournisseur $fournisseur): array
    {
        try {
            return $this->tarif->calculer($tarif, $remise, $achat, $vente, $fournisseur);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Prix impossible à calculer.';

            throw ValidationException::withMessages(['import' => sprintf('Ligne %d (%s) : %s Rien n\'a été appliqué.', $ligne->numero_ligne, $ligne->reference, $message)]);
        }
    }
}
