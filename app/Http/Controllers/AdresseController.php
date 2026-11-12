<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdresseRequest;
use App\Http\Requests\UpdateAdresseRequest;
use App\Models\Adresse;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Adresses d'un client (facturation et chantiers).
 *
 * Voir la liste : clients.voir. Creer, modifier, supprimer : clients.modifier
 * (une adresse fait partie de la fiche client).
 */
class AdresseController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:clients.voir', only: ['index']),
            new Middleware('can:clients.modifier', only: ['store', 'update', 'destroy']),
        ];
    }

    /**
     * Liste les adresses du client : facturation d'abord, puis chantiers.
     *
     * @param Client $client
     * @return JsonResponse
     */
    public function index(Client $client): JsonResponse
    {
        return response()->json(
            $client->adresses()->orderByDesc('type')->orderBy('id')->get()
        );
    }

    /**
     * Ajoute une adresse au client.
     *
     * @param StoreAdresseRequest $request
     * @param Client $client
     * @return JsonResponse
     */
    public function store(StoreAdresseRequest $request, Client $client): JsonResponse
    {
        $donnees = $request->validated();
        $donnees['pays'] = $donnees['pays'] ?? 'France';

        return response()->json($client->adresses()->create($donnees), 201);
    }

    /**
     * Modifie une adresse.
     *
     * @param UpdateAdresseRequest $request
     * @param Adresse $adresse
     * @return JsonResponse
     */
    public function update(UpdateAdresseRequest $request, Adresse $adresse): JsonResponse
    {
        $adresse->update($request->validated());

        return response()->json($adresse);
    }

    /**
     * Archive une adresse (suppression douce).
     *
     * @param Adresse $adresse
     * @return JsonResponse
     */
    public function destroy(Adresse $adresse): JsonResponse
    {
        $adresse->delete();

        return response()->json(null, 204);
    }
}
