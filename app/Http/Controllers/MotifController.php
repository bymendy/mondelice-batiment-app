<?php

namespace App\Http\Controllers;

use App\Models\Motif;
use App\Models\Opportunite;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Motifs de perte d'une affaire et d'ecart d'une demande. Lecture :
 * opportunites.voir. Creation, modification, suppression : Direction
 * (pipeline.parametrer). Un motif utilise ne se supprime pas : on le
 * desactive.
 */
class MotifController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:opportunites.voir', only: ['index']),
            new Middleware('can:pipeline.parametrer', only: ['store', 'update', 'destroy']),
        ];
    }

    /**
     * Query param facultatif : type=perte|ecart_demande.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Motif::query()
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
                ->orderBy('type')->orderBy('ordre')->orderBy('id')
                ->get()
        );
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in([Motif::TYPE_PERTE, Motif::TYPE_ECART_DEMANDE])],
            'libelle' => $this->reglesLibelle($request->input('type'), null, true),
            'actif' => ['sometimes', 'boolean'],
        ]);
        $donnees['ordre'] = (int) Motif::query()->where('type', $donnees['type'])->max('ordre') + 1;

        return response()->json(Motif::create($donnees)->refresh(), 201);
    }

    /**
     * Le type d'un motif ne change pas.
     *
     * @param Request $request
     * @param Motif $motif
     * @return JsonResponse
     */
    public function update(Request $request, Motif $motif): JsonResponse
    {
        $motif->update($request->validate([
            'libelle' => $this->reglesLibelle($motif->type, $motif->getKey(), false),
            'actif' => ['sometimes', 'boolean'],
        ]));

        return response()->json($motif);
    }

    /**
     * @param Motif $motif
     * @return JsonResponse
     *
     * @throws ValidationException Motif deja utilise.
     */
    public function destroy(Motif $motif): JsonResponse
    {
        if (Opportunite::query()->withTrashed()->where('motif_perte_id', $motif->getKey())->exists()) {
            throw ValidationException::withMessages(['motif' => 'Ce motif est utilise : desactivez-le plutot.']);
        }

        $motif->delete();

        return response()->json(null, 204);
    }

    /**
     * @param mixed $type Type du motif.
     * @param int|null $ignorer Motif modifie.
     * @param bool $obligatoire
     * @return array<int, mixed>
     */
    private function reglesLibelle(mixed $type, ?int $ignorer, bool $obligatoire): array
    {
        return [
            $obligatoire ? 'required' : 'sometimes', 'required', 'string', 'max:100',
            function (string $attribute, mixed $valeur, Closure $echec) use ($type, $ignorer): void {
                $existe = Motif::query()
                    ->where('type', $type)
                    ->whereRaw('lower(libelle) = ?', [mb_strtolower((string) $valeur)])
                    ->when($ignorer !== null, fn ($q) => $q->whereKeyNot($ignorer))
                    ->exists();
                if ($existe) {
                    $echec('Ce motif existe deja.');
                }
            },
        ];
    }
}
