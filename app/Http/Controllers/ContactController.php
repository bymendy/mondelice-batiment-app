<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\UpdateContactRequest;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * CRUD des contacts (personnes a joindre) du module Clients / CRM.
 *
 * Autorisation par permissions Spatie via le middleware natif "can:".
 */
class ContactController extends Controller implements HasMiddleware
{
    /**
     * Permission requise par action.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:contacts.voir', only: ['index', 'show']),
            new Middleware('can:contacts.creer', only: ['store']),
            new Middleware('can:contacts.modifier', only: ['update']),
            new Middleware('can:contacts.supprimer', only: ['destroy']),
        ];
    }

    /**
     * Liste paginee des contacts du tenant courant.
     *
     * Query params :
     *  - client_id=...  contacts rattaches a ce client
     *  - recherche=...  sur nom, prenom, email, telephone, mobile
     *  - par_page=...   taille de page (defaut 25)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query();

        if ($request->filled('client_id')) {
            $clientId = $request->integer('client_id');
            $query->whereHas('clients', fn ($q) => $q->whereKey($clientId));
        }

        if ($request->filled('recherche')) {
            $terme = $request->string('recherche');
            $query->where(function ($q) use ($terme): void {
                $q->where('nom', 'ilike', "%{$terme}%")
                    ->orWhere('prenom', 'ilike', "%{$terme}%")
                    ->orWhere('email', 'ilike', "%{$terme}%")
                    ->orWhere('telephone', 'ilike', "%{$terme}%")
                    ->orWhere('mobile', 'ilike', "%{$terme}%");
            });
        }

        return response()->json(
            $query->orderBy('nom')->orderBy('prenom')->paginate($request->integer('par_page', 25))
        );
    }

    /**
     * Cree un contact.
     *
     * @param StoreContactRequest $request
     * @return JsonResponse
     */
    public function store(StoreContactRequest $request): JsonResponse
    {
        return response()->json(Contact::create($request->validated()), 201);
    }

    /**
     * Affiche un contact avec ses clients.
     *
     * @param Contact $contact
     * @return JsonResponse
     */
    public function show(Contact $contact): JsonResponse
    {
        return response()->json($contact->load('clients'));
    }

    /**
     * Met a jour un contact.
     *
     * @param UpdateContactRequest $request
     * @param Contact $contact
     * @return JsonResponse
     */
    public function update(UpdateContactRequest $request, Contact $contact): JsonResponse
    {
        $contact->update($request->validated());

        return response()->json($contact);
    }

    /**
     * Archive le contact (suppression douce). Ses rattachements aux clients
     * sont conserves tant que la fiche n'est pas anonymisee.
     *
     * @param Contact $contact
     * @return JsonResponse
     */
    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();

        return response()->json(null, 204);
    }
}
