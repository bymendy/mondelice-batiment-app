<?php

namespace App\Http\Controllers;

use App\Models\AlerteSeuil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Liste des alertes de seuil de l'entreprise (lot D4b), de la plus récente à
 * la plus ancienne. Aucun total par personne : la liste suit les devis.
 */
class AlerteSeuilController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:alertes.seuils.recevoir')];
    }

    /**
     * @param Request $request Filtre facultatif : `devis_id`.
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $alertes = AlerteSeuil::query()
            ->with(['devis:id,numero,titre', 'auteur:id,name'])
            ->whereIn('devis_id', \App\Services\Devis\AccesDevis::visibles($request->user())->select('id'))
            ->when($request->filled('devis_id'), fn ($requete) => $requete->where('devis_id', $request->integer('devis_id')))
            ->orderByDesc('derniere_le')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($alertes);
    }
}
