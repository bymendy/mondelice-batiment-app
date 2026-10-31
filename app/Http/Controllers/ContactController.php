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
 * CRUD Contact / Client (tache 3.4).
 *
 * Autorisation par permissions Spatie (posees en Phase 2) via le
 * middleware natif "can:" : Direction et Commercial ecrivent, les autres
 * roles n'ont pas acces en ecriture.
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
     *  - type=particulier|professionnel
     *  - source_lead=...
     *  - recherche=...  sur nom, prenom, raison sociale, email, telephone, ville
     *  - par_page=...   taille de page (defaut 25)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('source_lead')) {
            $query->where('source_lead', $request->string('source_lead'));
        }

        if ($request->filled('recherche')) {
            $terme = $request->string('recherche');
            $query->where(function ($q) use ($terme): void {
                $q->where('nom', 'ilike', "%{$terme}%")
                    ->orWhere('prenom', 'ilike', "%{$terme}%")
                    ->orWhere('raison_sociale', 'ilike', "%{$terme}%")
                    ->orWhere('email', 'ilike', "%{$terme}%")
                    ->orWhere('telephone', 'ilike', "%{$terme}%")
                    ->orWhere('ville', 'ilike', "%{$terme}%");
            });
        }

        return response()->json(
            $query->orderBy('nom')->orderBy('raison_sociale')->paginate($request->integer('par_page', 25))
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
     * Affiche un contact.
     *
     * @param Contact $contact
     * @return JsonResponse
     */
    public function show(Contact $contact): JsonResponse
    {
        return response()->json($contact);
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
     * Archive le contact (suppression douce) : jamais de suppression
     * physique, un devis ou un chantier peut le referencer.
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
