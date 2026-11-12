<?php

namespace App\Http\Controllers;

use App\Services\Clients\DetecteurDoublons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Controle des doublons avant la creation (ou la modification) d'une fiche
 * client. Lecture seule : ne cree et ne bloque rien.
 */
class DoublonClientController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:clients.creer')];
    }

    /**
     * Query params (au moins un critere) : email, telephone, siret, ligne1 +
     * code_postal (l'adresse n'est testee que si les deux sont fournis),
     * ignorer_client_id (fiche en cours de modification).
     *
     * @param Request $request
     * @param DetecteurDoublons $detecteur
     * @return JsonResponse
     */
    public function __invoke(Request $request, DetecteurDoublons $detecteur): JsonResponse
    {
        $criteres = $request->validate([
            'email' => ['nullable', 'string', 'max:255', 'required_without_all:telephone,siret,ligne1'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'siret' => ['nullable', 'string', 'max:20'],
            'ligne1' => ['nullable', 'string', 'max:255'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ignorer_client_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $detecteur->chercher($criteres, $criteres['ignorer_client_id'] ?? null),
        ]);
    }
}
