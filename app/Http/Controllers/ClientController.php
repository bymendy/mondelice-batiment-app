<?php

namespace App\Http\Controllers;

use App\Enums\TypeAdresse;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * CRUD Client / prospect (module Clients / CRM).
 *
 * Autorisation par permissions Spatie via le middleware natif "can:".
 */
class ClientController extends Controller implements HasMiddleware
{
    /**
     * Permission requise par action.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:clients.voir', only: ['index', 'show']),
            new Middleware('can:clients.creer', only: ['store']),
            new Middleware('can:clients.modifier', only: ['update']),
            new Middleware('can:clients.supprimer', only: ['destroy']),
        ];
    }

    /**
     * Liste paginee des clients du tenant courant.
     *
     * Query params :
     *  - type=particulier|professionnel|syndic|prescripteur|collectivite
     *  - source_id=...
     *  - recherche=...  sur nom, prenom, raison sociale, email, telephone, ville
     *  - par_page=...   taille de page (defaut 25)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Client::query();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('source_id')) {
            $query->where('source_id', $request->integer('source_id'));
        }

        if ($request->filled('recherche')) {
            $terme = $request->string('recherche');
            $query->where(function ($q) use ($terme): void {
                $q->where('nom', 'ilike', "%{$terme}%")
                    ->orWhere('prenom', 'ilike', "%{$terme}%")
                    ->orWhere('raison_sociale', 'ilike', "%{$terme}%")
                    ->orWhere('email', 'ilike', "%{$terme}%")
                    ->orWhere('telephone', 'ilike', "%{$terme}%")
                    ->orWhereHas('adresses', fn ($a) => $a->where('ville', 'ilike', "%{$terme}%"));
            });
        }

        return response()->json(
            $query->orderBy('nom')->orderBy('raison_sociale')->paginate($request->integer('par_page', 25))
        );
    }

    /**
     * Cree un client.
     *
     * @param StoreClientRequest $request
     * @return JsonResponse
     */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $donnees = $request->validated();
        $adresse = array_filter(
            $donnees['adresse_facturation'] ?? [],
            fn ($v) => $v !== null && $v !== ''
        );
        unset($donnees['adresse_facturation']);

        $client = DB::transaction(function () use ($donnees, $adresse): Client {
            $client = Client::create($donnees);

            if ($adresse !== []) {
                $client->adresses()->create(array_merge($adresse, ['type' => TypeAdresse::Facturation->value]));
            }

            return $client;
        });

        return response()->json($client->load('adresses'), 201);
    }

    /**
     * Affiche un client avec ses contacts.
     *
     * @param Client $client
     * @return JsonResponse
     */
    public function show(Client $client): JsonResponse
    {
        return response()->json($client->load(['contacts', 'adresses']));
    }

    /**
     * Met a jour un client.
     *
     * @param UpdateClientRequest $request
     * @param Client $client
     * @return JsonResponse
     */
    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $client->update($request->validated());

        return response()->json($client);
    }

    /**
     * Archive le client (suppression douce) : jamais de suppression
     * physique, un devis ou un chantier peut le referencer.
     *
     * @param Client $client
     * @return JsonResponse
     */
    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(null, 204);
    }
}
