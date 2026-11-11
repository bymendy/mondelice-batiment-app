<?php

namespace App\Http\Controllers;

use App\Enums\TypeSeuil;
use App\Models\Activity;
use App\Models\SeuilTarifaire;
use App\Services\Bibliotheque\JournalPrix;
use App\Services\Bibliotheque\PresentateurHistorique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Seuils planchers de l'entreprise (lot 6). Reserve a la permission
 * `parametres.seuils` : la Direction seule fixe les seuils.
 *
 * Lecture et ecriture sont reservees a la Direction pour l'instant. La lecture
 * sera ouverte aux autres roles avec le module Devis (indicateur de marge,
 * Administratif sous les seuils).
 */
class SeuilTarifaireController extends Controller implements HasMiddleware
{
    public function __construct(private readonly JournalPrix $journal)
    {
    }

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:parametres.seuils'),
        ];
    }

    /**
     * Tous les types de seuil connus, avec la valeur de l'entreprise ou `null`
     * quand elle n'en a pas fixe.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $existants = SeuilTarifaire::query()->get()->keyBy(fn (SeuilTarifaire $s): string => $s->type->value);
        $liste = [];

        foreach (TypeSeuil::cases() as $type) {
            $seuil = $existants->get($type->value);
            $liste[] = $this->presenter($type, $seuil);
        }

        return response()->json(['data' => $liste]);
    }

    /**
     * Fixe ou modifie un seuil. Corps : `valeur` (marge minimum en %, de 0 a
     * moins de 100) et `actif` (facultatif ; vrai a la creation).
     *
     * @param Request $request
     * @param string $type Type de seuil (voir TypeSeuil).
     * @return JsonResponse
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException 404 si le type est inconnu.
     */
    public function update(Request $request, string $type): JsonResponse
    {
        $typeSeuil = TypeSeuil::tryFrom($type);
        abort_if($typeSeuil === null, 404);

        $donnees = $request->validate([
            'valeur' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'actif' => ['sometimes', 'boolean'],
        ]);

        $utilisateur = $request->user();

        $seuil = DB::transaction(function () use ($typeSeuil, $donnees, $utilisateur): SeuilTarifaire {
            $seuil = SeuilTarifaire::query()->firstOrNew(['type' => $typeSeuil->value]);
            $avant = $seuil->exists ? $this->journal->instantane($seuil, JournalPrix::CHAMPS_SEUIL) : null;

            $seuil->valeur = $donnees['valeur'];
            $seuil->actif = $donnees['actif'] ?? ($seuil->exists ? $seuil->actif : true);
            $seuil->defini_par = $utilisateur->getKey();
            $seuil->save();

            $this->journal->seuilModifie($utilisateur, $seuil, $avant);

            return $seuil;
        });

        return response()->json($this->presenter($typeSeuil, $seuil->refresh()));
    }

    /**
     * Historique des seuils de l'entreprise (fixes et modifies), du plus recent
     * au plus ancien. Query params : par_page (defaut 25).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function historique(Request $request): JsonResponse
    {
        $page = Activity::query()
            ->where('subject_type', (new SeuilTarifaire())->getMorphClass())
            ->with('causer')
            ->orderByDesc('id')
            ->paginate($request->integer('par_page', 25));

        $presentateur = PresentateurHistorique::pour($request->user());
        $page->through(fn (Activity $entree): array => $presentateur->presenter($entree));

        return response()->json($page);
    }

    /**
     * @param TypeSeuil $type
     * @param SeuilTarifaire|null $seuil
     * @return array<string, mixed>
     */
    private function presenter(TypeSeuil $type, ?SeuilTarifaire $seuil): array
    {
        return [
            'type' => $type->value,
            'libelle' => $type->label(),
            'valeur' => $seuil !== null ? (float) $seuil->valeur : null,
            'actif' => $seuil !== null ? $seuil->actif : false,
            'defini_par' => $seuil?->defini_par,
            'updated_at' => $seuil?->updated_at?->toIso8601String(),
        ];
    }
}
