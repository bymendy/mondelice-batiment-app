<?php

namespace App\Http\Controllers;

use App\Models\Fournisseur;
use App\Models\ImportFournitures;
use App\Models\ImportFournituresLigne;
use App\Services\Import\AnalyseLignesImport;
use App\Services\Import\ImportCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Import des catalogues des fournisseurs (tâche 3.6 bis, lot F3a).
 * Permission : bibliotheque.importer (Direction, Conducteur de travaux).
 *
 * Trois temps : envoyer le fichier (store), confirmer les colonnes
 * (correspondance), lire le comparatif (show, lignes). Rien n'est écrit dans
 * la Base de fournitures : l'application des lignes est le lot F3b.
 */
class ImportFournituresController extends Controller implements HasMiddleware
{
    /**
     * @param ImportCatalogue $service
     */
    public function __construct(private readonly ImportCatalogue $service)
    {
    }

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:bibliotheque.importer')];
    }

    /**
     * Filtres : `fournisseur_id`, `statut`.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = ImportFournitures::query()->with('fournisseur');

        if ($request->filled('fournisseur_id')) {
            $query->where('fournisseur_id', $request->integer('fournisseur_id'));
        }
        if ($request->filled('statut')) {
            $query->where('statut', (string) $request->string('statut'));
        }

        $page = $query->orderByDesc('id')->paginate($request->integer('par_page', 25));
        $page->through(fn (ImportFournitures $import): array => $this->presenter($import, false));

        return response()->json($page);
    }

    /**
     * Envoi du fichier (formulaire multipart) : `fichier` (CSV ou .xlsx,
     * 5 Mo et 5 000 lignes au plus), `fournisseur_id`, `mode` (`prix` ou
     * `complet`), `date_tarif` facultative. Lot F3c : `feuille` (index de la
     * feuille Excel, 0 par défaut) et `ligne_entete` (numéro de la ligne qui
     * porte les noms des colonnes, 1 par défaut).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'fichier' => ['required', 'file', 'max:5120'],
            'fournisseur_id' => ['required', 'integer'],
            'mode' => ['required', Rule::in(['prix', 'complet'])],
            'date_tarif' => ['nullable', 'date'],
            'feuille' => ['nullable', 'integer', 'min:0', 'max:200'],
            'ligne_entete' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $fournisseur = Fournisseur::query()->find((int) $donnees['fournisseur_id']);
        if ($fournisseur === null) {
            throw ValidationException::withMessages(['fournisseur_id' => 'Ce fournisseur n\'existe pas dans l\'entreprise.']);
        }

        $import = $this->service->envoyer(
            $request->file('fichier'),
            $fournisseur,
            $donnees['mode'],
            $donnees['date_tarif'] ?? null,
            $request->user(),
            (int) ($donnees['feuille'] ?? 0),
            (int) ($donnees['ligne_entete'] ?? 1)
        );

        activity()->performedOn($import)->causedBy($request->user())->event('import_fournitures_envoye')
            ->withProperties(['fournisseur_id' => $fournisseur->getKey(), 'fichier' => $import->nom_fichier, 'mode' => $import->mode, 'lignes' => $import->nb_lignes])
            ->log(sprintf('Catalogue « %s » envoyé pour %s (%d lignes).', $import->nom_fichier, $fournisseur->nom, $import->nb_lignes));

        return response()->json($this->presenter($import->load('fournisseur'), true), 201);
    }

    /**
     * @param int $import
     * @return JsonResponse
     */
    public function show(int $import): JsonResponse
    {
        return response()->json($this->presenter(ImportFournitures::query()->with('fournisseur')->findOrFail($import), true));
    }

    /**
     * Relit le fichier avec une autre feuille ou une autre ligne d'en-tête
     * (lot F3c) : `feuille`, `ligne_entete`. Un champ absent garde sa valeur.
     * L'import revient à l'étape des colonnes.
     *
     * @param Request $request
     * @param int $import
     * @return JsonResponse
     */
    public function lecture(Request $request, int $import): JsonResponse
    {
        $fiche = ImportFournitures::query()->with('fournisseur')->findOrFail($import);
        if (! in_array($fiche->statut, ['envoye', 'analyse'], true)) {
            throw ValidationException::withMessages(['import' => 'Cet import est terminé ou annulé : envoyez le fichier à nouveau.']);
        }

        $donnees = $request->validate([
            'feuille' => ['sometimes', 'required', 'integer', 'min:0', 'max:200'],
            'ligne_entete' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
        ]);
        $feuille = (int) ($donnees['feuille'] ?? $fiche->feuille);
        $ligneEntete = (int) ($donnees['ligne_entete'] ?? $fiche->ligne_entete);

        $fiche = $this->service->relire($fiche, $feuille, $ligneEntete);

        activity()->performedOn($fiche)->causedBy($request->user())->event('import_fournitures_relu')
            ->withProperties(['feuille' => $feuille, 'ligne_entete' => $ligneEntete, 'lignes' => $fiche->nb_lignes])
            ->log(sprintf('Catalogue « %s » relu (feuille %d, en-tête à la ligne %d).', $fiche->nom_fichier, $feuille + 1, $ligneEntete));

        return response()->json($this->presenter($fiche->load('fournisseur'), true));
    }

    /**
     * Confirme les colonnes et lance l'analyse. `correspondance` donne, pour
     * chaque champ, l'index (à partir de 0) de sa colonne dans le fichier :
     * `reference` (obligatoire), un seul de `prix_tarif_ht` ou `prix_achat_ht`
     * (obligatoire), `designation` (obligatoire en mode complet), `unite`,
     * `remise_pct` (seulement avec un prix tarif), `prix_vente_ht`.
     *
     * @param Request $request
     * @param int $import
     * @return JsonResponse
     */
    public function correspondance(Request $request, int $import): JsonResponse
    {
        $fiche = ImportFournitures::query()->with('fournisseur')->findOrFail($import);
        if (! in_array($fiche->statut, ['envoye', 'analyse'], true)) {
            throw ValidationException::withMessages(['import' => 'Cet import est terminé ou annulé : envoyez le fichier à nouveau.']);
        }

        $colonnes = count($fiche->entetes);
        $regles = ['correspondance' => ['required', 'array'], 'unite_defaut' => ['nullable', 'string', 'max:20']];
        foreach (AnalyseLignesImport::CHAMPS as $champ) {
            $regles["correspondance.{$champ}"] = ['nullable', 'integer', 'min:0', 'max:' . max(0, $colonnes - 1)];
        }
        $donnees = $request->validate($regles, [
            'correspondance.*.max' => 'Cette colonne n\'existe pas dans le fichier.',
        ]);

        $choix = [];
        foreach (AnalyseLignesImport::CHAMPS as $champ) {
            $valeur = $donnees['correspondance'][$champ] ?? null;
            $choix[$champ] = $valeur === null ? null : (int) $valeur;
        }

        $erreurs = [];
        if ($choix['reference'] === null) {
            $erreurs['correspondance.reference'] = 'Indiquez la colonne de la référence.';
        }
        if (($choix['prix_tarif_ht'] === null) === ($choix['prix_achat_ht'] === null)) {
            $erreurs['correspondance.prix_tarif_ht'] = 'Indiquez une seule colonne de prix : le prix tarif ou le prix d\'achat net.';
        }
        if ($fiche->mode === 'complet' && $choix['designation'] === null) {
            $erreurs['correspondance.designation'] = 'En import complet, indiquez la colonne de la désignation.';
        }
        if ($choix['remise_pct'] !== null && $choix['prix_tarif_ht'] === null) {
            $erreurs['correspondance.remise_pct'] = 'La remise ne s\'applique qu\'à un prix tarif.';
        }
        $utilisees = array_filter($choix, static fn (?int $v): bool => $v !== null);
        if (count($utilisees) !== count(array_unique($utilisees))) {
            $erreurs['correspondance'] = 'Une même colonne ne peut pas servir à deux champs.';
        }
        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        $fiche = $this->service->analyser($fiche, $choix, $donnees['unite_defaut'] ?? 'u');

        activity()->performedOn($fiche)->causedBy($request->user())->event('import_fournitures_analyse')
            ->withProperties(['correspondance' => $choix, 'resume' => $fiche->resume])
            ->log(sprintf('Catalogue « %s » analysé.', $fiche->nom_fichier));

        return response()->json($this->presenter($fiche->load('fournisseur'), true));
    }

    /**
     * Comparatif, ligne par ligne. Filtres : `action`, `a_valider`, `retenue`.
     *
     * @param Request $request
     * @param int $import
     * @return JsonResponse
     */
    public function lignes(Request $request, int $import): JsonResponse
    {
        $fiche = ImportFournitures::query()->findOrFail($import);
        $query = ImportFournituresLigne::query()->where('import_id', $fiche->getKey());

        if ($request->filled('action')) {
            $query->where('action', (string) $request->string('action'));
        }
        foreach (['a_valider', 'retenue'] as $filtre) {
            if ($request->filled($filtre)) {
                $query->where($filtre, $request->boolean($filtre));
            }
        }

        return response()->json($query->orderBy('numero_ligne')->paginate($request->integer('par_page', 50)));
    }

    /**
     * Annule l'import : le fichier et le comparatif sont supprimés.
     *
     * @param Request $request
     * @param int $import
     * @return JsonResponse
     */
    public function destroy(Request $request, int $import): JsonResponse
    {
        $fiche = ImportFournitures::query()->findOrFail($import);
        if ($fiche->statut === 'applique') {
            throw ValidationException::withMessages(['import' => 'Un import déjà appliqué ne s\'annule pas.']);
        }

        if ($fiche->statut !== 'annule') {
            $this->service->annuler($fiche);
            activity()->performedOn($fiche)->causedBy($request->user())->event('import_fournitures_annule')
                ->log(sprintf('Import du catalogue « %s » annulé.', $fiche->nom_fichier));
        }

        return response()->json(null, 204);
    }

    /**
     * Avertissements de l'import : feuilles non lues, puis formats de nombres
     * relevés par l'analyse.
     *
     * @param ImportFournitures $import
     * @return array<int, string>
     */
    private function avertissements(ImportFournitures $import): array
    {
        $avertissements = [];
        $feuilles = $import->feuilles ?? [];

        if (count($feuilles) > 1) {
            $avertissements[] = sprintf(
                'Ce fichier contient %d feuilles : seule « %s » est lue. Pour les autres, choisissez une autre feuille ou envoyez le fichier à nouveau.',
                count($feuilles),
                $feuilles[(int) $import->feuille] ?? $feuilles[0]
            );
        }

        return array_merge($avertissements, $import->avertissements ?? []);
    }

    /**
     * @param ImportFournitures $import
     * @param bool $detail Avec l'en-tête, l'aperçu et les colonnes.
     * @return array<string, mixed>
     */
    private function presenter(ImportFournitures $import, bool $detail): array
    {
        $fournisseur = $import->fournisseur;
        $donnees = [
            'id' => $import->id,
            'fournisseur' => $fournisseur !== null ? ['id' => $fournisseur->id, 'nom' => $fournisseur->nom] : null,
            'nom_fichier' => $import->nom_fichier,
            'format' => $import->format,
            'mode' => $import->mode,
            'statut' => $import->statut,
            'date_tarif' => $import->date_tarif?->format('Y-m-d'),
            'nb_lignes' => $import->nb_lignes,
            'resume' => $import->resume,
            'cree_le' => $import->created_at?->toIso8601String(),
            'applique_le' => $import->applique_le?->toIso8601String(),
            'applique_par' => $import->applique_par !== null ? ['id' => (int) $import->applique_par] : null,
            'bilan' => $import->bilan,
        ];

        if ($detail) {
            $donnees += [
                'entetes' => $import->entetes,
                'apercu' => $import->apercu,
                'correspondance_proposee' => $import->correspondance_proposee,
                'correspondance' => $import->correspondance,
                'unite_defaut' => $import->unite_defaut,
                'seuil_ecart_pct' => $import->seuil_ecart_pct !== null ? (float) $import->seuil_ecart_pct : null,
                'limite_lignes' => ImportCatalogue::MAX_LIGNES,
                'feuilles' => $import->feuilles ?? [],
                'feuille' => (int) $import->feuille,
                'ligne_entete' => (int) $import->ligne_entete,
                'debut_fichier' => $import->debut_fichier ?? [],
                'avertissements' => $this->avertissements($import),
            ];
        }

        return $donnees;
    }
}
