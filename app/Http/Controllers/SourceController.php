<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Opportunite;
use App\Models\Source;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * Sources des clients et des affaires. Lecture : clients.voir ou
 * opportunites.voir (le formulaire client en a besoin). Creation,
 * modification, suppression : Direction (pipeline.parametrer).
 *
 * Une source utilisee ne se supprime pas : on la desactive. La source
 * systeme « Import / reprise » ne se supprime jamais.
 */
class SourceController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:pipeline.parametrer', only: ['store', 'update', 'destroy'])];
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();
        abort_unless($utilisateur->can('clients.voir') || $utilisateur->can('opportunites.voir'), 403);

        return response()->json(Source::query()->orderBy('ordre')->orderBy('id')->get());
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate($this->regles(null));
        $donnees['ordre'] = (int) Source::query()->max('ordre') + 1;

        return response()->json(Source::create($donnees)->refresh(), 201);
    }

    /**
     * @param Request $request
     * @param Source $source
     * @return JsonResponse
     */
    public function update(Request $request, Source $source): JsonResponse
    {
        $source->update($request->validate($this->regles($source->getKey())));

        return response()->json($source);
    }

    /**
     * @param Source $source
     * @return JsonResponse
     *
     * @throws ValidationException Source systeme ou deja utilisee.
     */
    public function destroy(Source $source): JsonResponse
    {
        if ($source->systeme) {
            throw ValidationException::withMessages(['source' => 'Cette source systeme ne peut pas etre supprimee.']);
        }

        $utilisee = Client::query()->withTrashed()->where('source_id', $source->getKey())->exists()
            || Opportunite::query()->withTrashed()->where('source_id', $source->getKey())->exists();
        if ($utilisee) {
            throw ValidationException::withMessages(['source' => 'Cette source est utilisee : desactivez-la plutot.']);
        }

        $source->delete();

        return response()->json(null, 204);
    }

    /**
     * @param int|null $ignorer Source modifiee (nom unique hors elle-meme).
     * @return array<string, mixed>
     */
    private function regles(?int $ignorer): array
    {
        return [
            'nom' => [
                $ignorer === null ? 'required' : 'sometimes', 'required', 'string', 'max:100',
                function (string $attribute, mixed $valeur, Closure $echec) use ($ignorer): void {
                    $existe = Source::query()
                        ->whereRaw('lower(nom) = ?', [mb_strtolower((string) $valeur)])
                        ->when($ignorer !== null, fn ($q) => $q->whereKeyNot($ignorer))
                        ->exists();
                    if ($existe) {
                        $echec('Cette source existe deja.');
                    }
                },
            ],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
