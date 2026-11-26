<?php

namespace App\Http\Controllers;

use App\Models\ImportFournitures;
use App\Models\ImportFournituresLigne;
use App\Services\Import\ApplicationImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Choix des lignes et application d'un import de catalogue (tâche 3.6 bis,
 * lot F3b). Permission : bibliotheque.importer.
 *
 * Seules les lignes `creer` et `modifier` se retiennent. Cocher une ligne
 * « à valider » (écart de prix au-delà du seuil), c'est la valider.
 */
class ImportFournituresApplicationController extends Controller implements HasMiddleware
{
    /**
     * @param ApplicationImport $service
     */
    public function __construct(private readonly ApplicationImport $service)
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
     * Retient ou écarte une ligne : `retenue` (booléen).
     *
     * @param Request $request
     * @param int $import
     * @param int $ligne
     * @return JsonResponse
     */
    public function retenir(Request $request, int $import, int $ligne): JsonResponse
    {
        $fiche = $this->importAnalyse($import);
        $cible = ImportFournituresLigne::query()->where('import_id', $fiche->getKey())->findOrFail($ligne);
        $donnees = $request->validate(['retenue' => ['required', 'boolean']]);

        if (! in_array($cible->action, ['creer', 'modifier'], true)) {
            throw ValidationException::withMessages([
                'retenue' => 'Cette ligne ne s\'applique pas (inchangée, ignorée ou en erreur).',
            ]);
        }

        $cible->update(['retenue' => (bool) $donnees['retenue']]);

        return response()->json(['ligne' => $cible->refresh(), 'resume' => $this->resume($fiche)]);
    }

    /**
     * Retient ou écarte plusieurs lignes d'un coup : `retenue` (booléen),
     * avec les filtres facultatifs `action` (`creer` ou `modifier`) et
     * `a_valider` (booléen). Sans filtre : toutes les lignes applicables.
     *
     * @param Request $request
     * @param int $import
     * @return JsonResponse
     */
    public function retenirPlusieurs(Request $request, int $import): JsonResponse
    {
        $fiche = $this->importAnalyse($import);
        $donnees = $request->validate([
            'retenue' => ['required', 'boolean'],
            'action' => ['nullable', Rule::in(['creer', 'modifier'])],
            'a_valider' => ['nullable', 'boolean'],
        ]);

        $query = ImportFournituresLigne::query()
            ->where('import_id', $fiche->getKey())
            ->whereIn('action', ['creer', 'modifier']);
        if (isset($donnees['action'])) {
            $query->where('action', $donnees['action']);
        }
        if (isset($donnees['a_valider'])) {
            $query->where('a_valider', (bool) $donnees['a_valider']);
        }
        $nombre = $query->update(['retenue' => (bool) $donnees['retenue']]);

        return response()->json(['lignes_changees' => $nombre, 'resume' => $this->resume($fiche)]);
    }

    /**
     * Applique les lignes retenues à la Base de fournitures (tout ou rien).
     *
     * @param Request $request
     * @param int $import
     * @return JsonResponse
     */
    public function appliquer(Request $request, int $import): JsonResponse
    {
        $fiche = $this->importAnalyse($import);
        $bilan = $this->service->appliquer($fiche, $request->user());

        return response()->json([
            'id' => $fiche->id,
            'statut' => 'applique',
            'applique_le' => $fiche->refresh()->applique_le?->toIso8601String(),
            'bilan' => $bilan,
        ]);
    }

    /**
     * @param int $import
     * @return ImportFournitures
     *
     * @throws ValidationException Si l'import n'est pas au statut `analyse`.
     */
    private function importAnalyse(int $import): ImportFournitures
    {
        $fiche = ImportFournitures::query()->findOrFail($import);

        if ($fiche->statut !== 'analyse') {
            throw ValidationException::withMessages(['import' => match ($fiche->statut) {
                'envoye' => 'Confirmez d\'abord les colonnes pour lancer l\'analyse.',
                'applique' => 'Cet import est déjà appliqué.',
                default => 'Cet import est annulé.',
            }]);
        }

        return $fiche;
    }

    /**
     * Remet à jour le nombre de lignes retenues dans le résumé de l'import.
     *
     * @param ImportFournitures $import
     * @return array<string, int>
     */
    private function resume(ImportFournitures $import): array
    {
        $resume = $import->resume ?? [];
        $resume['retenues'] = ImportFournituresLigne::query()
            ->where('import_id', $import->getKey())->where('retenue', true)->count();
        $import->update(['resume' => $resume]);

        return $resume;
    }
}
