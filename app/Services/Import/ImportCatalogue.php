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
 * Ce lot n'écrit RIEN dans la Base de fournitures : il prépare le comparatif
 * que le lot F3b appliquera après validation.
 */
final class ImportCatalogue
{
    /** Lignes de données par fichier (sans l'en-tête). */
    public const MAX_LIGNES = 5000;

    public const DISQUE = 'local';

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
     * @return ImportFournitures
     *
     * @throws ValidationException Si le fichier est illisible, vide ou trop long.
     */
    public function envoyer(UploadedFile $fichier, Fournisseur $fournisseur, string $mode, ?string $dateTarif, ?User $auteur): ImportFournitures
    {
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
            $lignes = $this->lire((string) $chemin, $format);
        } catch (ValidationException $e) {
            Storage::disk(self::DISQUE)->delete((string) $chemin);

            throw $e;
        }

        $entetes = array_shift($lignes);
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
        ]);
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
        $lignes = $this->lire($import->chemin, $import->format);
        array_shift($lignes);

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
        $analyse = $this->analyse->analyser($lignes, $correspondance, $import->mode, $existantes, $seuil, $uniteDefaut);
        $resume = $this->analyse->resumer($analyse);

        DB::transaction(function () use ($import, $analyse, $resume, $correspondance, $uniteDefaut, $seuil): void {
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
     * Lit le fichier : en-tête puis lignes de données.
     *
     * @param string $chemin
     * @param string $format
     * @return array<int, array<int, string>>
     *
     * @throws ValidationException
     */
    private function lire(string $chemin, string $format): array
    {
        try {
            $lecture = $this->lecteur->lire(Storage::disk(self::DISQUE)->path($chemin), $format, self::MAX_LIGNES + 1);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['fichier' => $e->getMessage()]);
        }

        if ($lecture['tronque']) {
            throw ValidationException::withMessages([
                'fichier' => sprintf('Le fichier dépasse %d lignes : découpez-le en plusieurs fichiers.', self::MAX_LIGNES),
            ]);
        }
        if (count($lecture['lignes']) < 2) {
            throw ValidationException::withMessages([
                'fichier' => 'Le fichier doit contenir une ligne d\'en-tête et au moins une ligne de données.',
            ]);
        }

        return $lecture['lignes'];
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
