<?php

namespace App\Services\Import;

use App\Models\Fournisseur;
use App\Models\Fourniture;
use App\Models\ImportFournitures;
use App\Models\ImportFournituresLigne;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Import du catalogue d'un fournisseur (tâche 3.6 bis, lot F3a) : envoi du
 * fichier, proposition des colonnes, analyse et comparatif.
 *
 * Ce service n'écrit RIEN dans la Base de fournitures : il prépare le
 * comparatif que le lot F3b applique après validation.
 *
 * Lot F3c : choix de la feuille Excel et de la ligne d'en-tête, numéros de
 * lignes réels, nombres lus avec le séparateur décimal de leur colonne, et
 * avertissements quand le fichier laisse un doute.
 */
final class ImportCatalogue
{
    /** Lignes de données par fichier (sans l'en-tête). */
    public const MAX_LIGNES = 5000;

    public const DISQUE = 'local';

    /** Lignes du début du fichier montrées pour choisir la ligne d'en-tête. */
    public const LIGNES_DEBUT = 10;

    /** Champs numériques dont on déduit le format par colonne. */
    private const CHAMPS_NOMBRES = ['prix_tarif_ht', 'prix_achat_ht', 'remise_pct', 'prix_vente_ht'];

    /**
     * @param LecteurTableur $lecteur
     * @param AnalyseLignesImport $analyse
     */
    public function __construct(private readonly LecteurTableur $lecteur, private readonly AnalyseLignesImport $analyse)
    {
    }

    /**
     * Enregistre le fichier, lit son en-tête et propose les colonnes.
     *
     * @param UploadedFile $fichier
     * @param Fournisseur $fournisseur
     * @param string $mode `prix` ou `complet`.
     * @param string|null $dateTarif
     * @param User|null $auteur
     * @param int $feuille Index de la feuille Excel (0 pour la première).
     * @param int $ligneEntete Numéro, dans le fichier, de la ligne d'en-tête.
     * @return ImportFournitures
     *
     * @throws ValidationException Si le fichier est illisible, vide ou trop long.
     */
    public function envoyer(
        UploadedFile $fichier,
        Fournisseur $fournisseur,
        string $mode,
        ?string $dateTarif,
        ?User $auteur,
        int $feuille = 0,
        int $ligneEntete = 1
    ): ImportFournitures {
        $format = self::format($fichier->getClientOriginalName());
        if ($format === null) {
            throw ValidationException::withMessages(['fichier' => 'Le fichier doit être un CSV ou un Excel (.xlsx).']);
        }

        $chemin = $fichier->storeAs(
            'imports-fournitures/' . tenant_id(),
            Str::uuid()->toString() . '.' . $format,
            self::DISQUE
        );

        try {
            $lecture = $this->lire((string) $chemin, $format, $feuille, $ligneEntete);
        } catch (ValidationException $e) {
            Storage::disk(self::DISQUE)->delete((string) $chemin);

            throw $e;
        }

        $entetes = $lecture['entetes'];
        $lignes = $lecture['lignes'];
        $proposee = $this->correspondanceProposee($fournisseur, $entetes);

        return ImportFournitures::create([
            'fournisseur_id' => $fournisseur->getKey(),
            'user_id' => $auteur?->getKey(),
            'nom_fichier' => mb_substr($fichier->getClientOriginalName(), 0, 255),
            'chemin' => $chemin,
            'format' => $format,
            'mode' => $mode,
            'statut' => 'envoye',
            'date_tarif' => $dateTarif,
            'nb_lignes' => count($lignes),
            'entetes' => $entetes,
            'apercu' => array_slice($lignes, 0, 5),
            'correspondance_proposee' => $proposee,
            'feuilles' => $lecture['feuilles'],
            'feuille' => $feuille,
            'ligne_entete' => $ligneEntete,
            'debut_fichier' => $lecture['debut'],
        ]);
    }

    /**
     * Relit le fichier avec une autre feuille ou une autre ligne d'en-tête.
     * L'import revient à l'étape des colonnes : le comparatif d'une analyse
     * précédente est supprimé.
     *
     * @param ImportFournitures $import
     * @param int $feuille
     * @param int $ligneEntete
     * @return ImportFournitures
     *
     * @throws ValidationException Si la feuille ou la ligne ne convient pas.
     */
    public function relire(ImportFournitures $import, int $feuille, int $ligneEntete): ImportFournitures
    {
        $lecture = $this->lire($import->chemin, $import->format, $feuille, $ligneEntete);
        $fournisseur = Fournisseur::withTrashed()->findOrFail($import->fournisseur_id);
        $proposee = $this->correspondanceProposee($fournisseur, $lecture['entetes']);

        DB::transaction(function () use ($import, $lecture, $proposee, $feuille, $ligneEntete): void {
            ImportFournituresLigne::query()->where('import_id', $import->getKey())->delete();
            $import->update([
                'statut' => 'envoye',
                'nb_lignes' => count($lecture['lignes']),
                'entetes' => $lecture['entetes'],
                'apercu' => array_slice($lecture['lignes'], 0, 5),
                'correspondance_proposee' => $proposee,
                'correspondance' => null,
                'resume' => null,
                'avertissements' => null,
                'feuilles' => $lecture['feuilles'],
                'feuille' => $feuille,
                'ligne_entete' => $ligneEntete,
                'debut_fichier' => $lecture['debut'],
            ]);
        });

        return $import->refresh();
    }

    /**
     * Analyse le fichier avec la correspondance choisie et enregistre le
     * comparatif (les lignes d'une analyse précédente sont remplacées).
     *
     * @param ImportFournitures $import
     * @param array<string, int|null> $correspondance Champ -> index de colonne.
     * @param string $uniteDefaut
     * @return ImportFournitures
     */
    public function analyser(ImportFournitures $import, array $correspondance, string $uniteDefaut): ImportFournitures
    {
        $lecture = $this->lire($import->chemin, $import->format, (int) $import->feuille, (int) $import->ligne_entete);
        $lignes = $lecture['lignes'];
        // Dans un fichier Excel, un nombre est enregistré avec un point décimal, sans séparateur de milliers.
        $pointSur = $import->format === 'xlsx';

        $conventions = [];
        $avertissements = [];
        foreach (self::CHAMPS_NOMBRES as $champ) {
            $colonne = $correspondance[$champ] ?? null;
            if ($colonne === null) {
                continue;
            }
            $convention = NormalisationImport::convention(array_column($lignes, $colonne), $pointSur);
            $conventions[$champ] = $convention;
            $entete = $lecture['entetes'][$colonne] ?? ('colonne ' . ($colonne + 1));
            if ($convention['etat'] === 'ambigu') {
                $avertissements[] = sprintf(
                    'Colonne « %s » : le format des nombres est équivoque (« %s » est lu %s, et non comme un millier). '
                    . 'Les lignes concernées attendent votre validation : vérifiez-les avant d\'appliquer.',
                    $entete,
                    $convention['exemple'],
                    str_replace('.', ',', (string) NormalisationImport::nombre($convention['exemple'], null, $pointSur))
                );
            } elseif ($convention['etat'] === 'melange') {
                $avertissements[] = sprintf(
                    'Colonne « %s » : les nombres mélangent deux formats (virgule décimale et point décimal). Vérifiez le comparatif avant d\'appliquer.',
                    $entete
                );
            }
        }

        $existantes = [];
        foreach (Fourniture::query()->where('fournisseur_id', $import->fournisseur_id)->whereNotNull('reference')->get() as $fourniture) {
            $existantes[(string) $fourniture->reference] = [
                'id' => (int) $fourniture->getKey(),
                'designation' => $fourniture->designation,
                'unite' => $fourniture->unite,
                'prix_tarif_ht' => $fourniture->prix_tarif_ht,
                'prix_achat_ht' => $fourniture->prix_achat_ht,
                'remise_pct' => $fourniture->remise_pct,
                'prix_vente_ht' => $fourniture->prix_vente_ht,
            ];
        }

        $seuil = (float) Parametre::lire('import_ecart_prix_pct');
        $analyse = $this->analyse->analyser(
            $lignes,
            $correspondance,
            $import->mode,
            $existantes,
            $seuil,
            $uniteDefaut,
            (int) $import->ligne_entete + 1,
            $lecture['numeros'],
            $conventions,
            $pointSur
        );
        $resume = $this->analyse->resumer($analyse);

        DB::transaction(function () use ($import, $analyse, $resume, $correspondance, $uniteDefaut, $seuil, $avertissements): void {
            ImportFournituresLigne::query()->where('import_id', $import->getKey())->delete();

            $maintenant = now();
            foreach (array_chunk($analyse, 500) as $paquet) {
                ImportFournituresLigne::query()->insert(array_map(function (array $ligne) use ($import, $maintenant): array {
                    foreach (['anciennes', 'modifications', 'erreurs'] as $champ) {
                        $ligne[$champ] = $ligne[$champ] === null ? null : json_encode($ligne[$champ], JSON_UNESCAPED_UNICODE);
                    }

                    return $ligne + [
                        'tenant_id' => $import->tenant_id,
                        'import_id' => $import->getKey(),
                        'created_at' => $maintenant,
                        'updated_at' => $maintenant,
                    ];
                }, $paquet));
            }

            $import->update([
                'statut' => 'analyse',
                'correspondance' => $correspondance,
                'unite_defaut' => $uniteDefaut,
                'seuil_ecart_pct' => $seuil,
                'resume' => $resume,
                'avertissements' => $avertissements === [] ? null : $avertissements,
            ]);
        });

        return $import->refresh();
    }

    /**
     * Annule un import : son fichier et son comparatif sont supprimés.
     *
     * @param ImportFournitures $import
     * @return void
     */
    public function annuler(ImportFournitures $import): void
    {
        DB::transaction(function () use ($import): void {
            ImportFournituresLigne::query()->where('import_id', $import->getKey())->delete();
            $import->update(['statut' => 'annule']);
        });
        Storage::disk(self::DISQUE)->delete($import->chemin);
    }

    /**
     * @param string $nom Nom d'origine du fichier.
     * @return string|null `csv`, `xlsx`, ou null si l'extension n'est pas acceptée.
     */
    public static function format(string $nom): ?string
    {
        return match (strtolower(pathinfo($nom, PATHINFO_EXTENSION))) {
            'csv', 'txt' => 'csv',
            'xlsx' => 'xlsx',
            default => null,
        };
    }

    /**
     * Lit le fichier : feuilles, début du fichier, en-tête, lignes de données
     * et numéro réel de chaque ligne.
     *
     * @param string $chemin
     * @param string $format
     * @param int $feuille Index de la feuille Excel.
     * @param int $ligneEntete Numéro de la ligne d'en-tête dans le fichier.
     * @return array{feuilles: array<int, string>, debut: array<int, array{numero: int, cellules: array<int, string>}>, entetes: array<int, string>, lignes: array<int, array<int, string>>, numeros: array<int, int>}
     *
     * @throws ValidationException
     */
    private function lire(string $chemin, string $format, int $feuille = 0, int $ligneEntete = 1): array
    {
        $fichier = Storage::disk(self::DISQUE)->path($chemin);

        try {
            $feuilles = $this->lecteur->feuilles($fichier, $format);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['fichier' => $e->getMessage()]);
        }
        if ($feuille !== 0 && ! isset($feuilles[$feuille])) {
            throw ValidationException::withMessages(['feuille' => 'Cette feuille n\'existe pas dans le fichier.']);
        }

        try {
            $debut = $this->lecteur->lire($fichier, $format, self::LIGNES_DEBUT, $feuille);
            $lecture = $this->lecteur->lire($fichier, $format, self::MAX_LIGNES + 1, $feuille, $ligneEntete);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['fichier' => $e->getMessage()]);
        }

        if ($lecture['tronque']) {
            throw ValidationException::withMessages([
                'fichier' => sprintf('Le fichier dépasse %d lignes : découpez-le en plusieurs fichiers.', self::MAX_LIGNES),
            ]);
        }
        if ($ligneEntete > 1 && ($lecture['numeros'][0] ?? null) !== $ligneEntete) {
            throw ValidationException::withMessages([
                'ligne_entete' => sprintf('La ligne %d est vide ou absente : choisissez la ligne qui porte les noms des colonnes.', $ligneEntete),
            ]);
        }
        if (count($lecture['lignes']) < 2) {
            throw ValidationException::withMessages([
                'fichier' => 'Le fichier doit contenir une ligne d\'en-tête et au moins une ligne de données.',
            ]);
        }

        $lignes = $lecture['lignes'];
        $numeros = $lecture['numeros'];
        $entetes = array_shift($lignes);
        array_shift($numeros);

        $premieres = [];
        foreach ($debut['lignes'] as $rang => $cellules) {
            $premieres[] = ['numero' => $debut['numeros'][$rang], 'cellules' => $cellules];
        }

        return ['feuilles' => $feuilles, 'debut' => $premieres, 'entetes' => $entetes, 'lignes' => $lignes, 'numeros' => $numeros];
    }

    /**
     * Colonnes proposées : celles du dernier import de ce fournisseur si les
     * en-têtes sont les mêmes, sinon une proposition d'après les en-têtes.
     *
     * @param Fournisseur $fournisseur
     * @param array<int, string> $entetes
     * @return array<string, int|null>
     */
    private function correspondanceProposee(Fournisseur $fournisseur, array $entetes): array
    {
        $precedents = ImportFournitures::query()
            ->where('fournisseur_id', $fournisseur->getKey())
            ->whereNotNull('correspondance')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($precedents as $precedent) {
            if ($precedent->entetes === $entetes) {
                return array_merge(array_fill_keys(AnalyseLignesImport::CHAMPS, null), $precedent->correspondance ?? []);
            }
        }

        return array_merge(array_fill_keys(AnalyseLignesImport::CHAMPS, null), NormalisationImport::proposer($entetes));
    }
}
