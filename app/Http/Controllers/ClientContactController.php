<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * Rattachement des contacts a un client (table pivot client_contact).
 * Modifier les contacts d'un client demande la permission clients.modifier.
 */
class ClientContactController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:clients.modifier'),
        ];
    }

    /**
     * Rattache un contact au client (ou met a jour son role / son statut
     * de contact principal).
     *
     * @param Request $request
     * @param Client $client
     * @return JsonResponse Les contacts du client apres rattachement.
     *
     * @throws ValidationException Si le contact n'existe pas dans l'entreprise.
     */
    public function store(Request $request, Client $client): JsonResponse
    {
        $donnees = $request->validate([
            'contact_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:30'],
            'principal' => ['nullable', 'boolean'],
        ]);

        // La requete passe par le filtre tenant : un contact d'une autre
        // entreprise est introuvable.
        $contact = Contact::query()->find($donnees['contact_id']);

        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => 'Contact introuvable.']);
        }

        $client->rattacherContact(
            $contact,
            $donnees['role'] ?? null,
            (bool) ($donnees['principal'] ?? false)
        );

        return response()->json($client->contacts()->get(), 201);
    }

    /**
     * Retire le rattachement d'un contact au client.
     *
     * @param Client $client
     * @param Contact $contact
     * @return JsonResponse
     */
    public function destroy(Client $client, Contact $contact): JsonResponse
    {
        $client->detacherContact($contact);

        return response()->json(null, 204);
    }
}
