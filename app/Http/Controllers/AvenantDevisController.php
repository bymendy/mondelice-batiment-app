<?php

namespace App\Http\Controllers;

use App\Models\Devis;
use App\Services\Devis\AccesDevis;
use App\Services\Devis\StatutsDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création d'un avenant (lot D5, décisions du 20 novembre 2026).
 *
 *  - Seul un devis accepté reçoit un avenant ; un avenant se rattache toujours
 *    au devis d'origine, jamais à un autre avenant.
 *  - L'avenant part vide : il ne porte que les changements (travaux en plus,
 *    travaux en moins en quantité négative). Il reprend du devis d'origine le
 *    client, l'adresse du chantier, l'affaire et la portée.
 *  - Numéro : `<numéro du devis d'origine>-A<rang>`. Le rang est donné à la
 *    création et n'est jamais réutilisé. Plusieurs avenants peuvent coexister.
 *  - L'avenant ne crée pas d'affaire et ne déplace pas celle du devis d'origine.
 * Il suit ensuite le cycle de vie et les contrôles d'un devis.
 */
class AvenantDevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:devis.creer_avenant')];
    }

    /**
     * @param Request $request Champs facultatifs : `titre`, `notes`, `validite_jours`.
     * @param int $devis Le devis d'origine.
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function store(Request $request, int $devis): JsonResponse
    {
        $origine = AccesDevis::visibles($request->user())->findOrFail($devis);

        if ($origine->devis_parent_id !== null) {
            throw ValidationException::withMessages([
                'devis' => 'Un avenant se rattache au devis d\'origine, pas à un autre avenant.',
            ]);
        }
        if ((string) $origine->statut !== 'accepte') {
            throw ValidationException::withMessages([
                'devis' => 'Seul un devis accepté peut recevoir un avenant.',
            ]);
        }

        $donnees = $request->validate([
            'titre' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'validite_jours' => ['sometimes', 'integer', 'between:1,365'],
        ]);
        $auteur = $request->user();

        $avenant = DB::transaction(function () use ($origine, $donnees, $auteur): Devis {
            // Un seul rang à la fois par devis d'origine, même si deux demandes arrivent ensemble.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$origine->tenant_id . '|avenants|' . $origine->getKey()]);

            $rang = ((int) Devis::withTrashed()->where('devis_parent_id', $origine->getKey())->max('rang_avenant')) + 1;
            $reference = $origine->numero ?? 'DEVIS-' . $origine->getKey();

            $avenant = new Devis([
                'client_id' => $origine->client_id,
                'opportunite_id' => $origine->opportunite_id,
                'adresse_chantier_id' => $origine->adresse_chantier_id,
                'titre' => ($donnees['titre'] ?? null) ?: sprintf('Avenant n° %d au devis %s', $rang, $reference),
                'portee' => $origine->portee,
                'auteur_id' => $auteur->getKey(),
                'date_devis' => now()->toDateString(),
                'validite_jours' => $donnees['validite_jours'] ?? 30,
                'notes' => $donnees['notes'] ?? null,
            ]);
            $avenant->date_expiration = $avenant->date_devis->copy()->addDays($avenant->validite_jours);
            $avenant->forceFill([
                'devis_parent_id' => $origine->getKey(),
                'rang_avenant' => $rang,
                'numero' => $reference . '-A' . $rang,
            ]);
            $avenant->save();
            app(\App\Services\Devis\ParametresDevis::class)->creer($avenant);

            return $avenant;
        });

        activity()
            ->performedOn($avenant)
            ->causedBy($auteur)
            ->event('created')
            ->withProperties(['devis_origine_id' => $origine->getKey(), 'devis_origine' => $origine->numero, 'rang' => $avenant->rang_avenant])
            ->log(sprintf('Avenant %s créé sur le devis %s.', $avenant->numero, $origine->numero));

        $avenant->refresh();

        return response()->json(array_merge($avenant->toArray(), [
            'statut_libelle' => StatutsDevis::libelle((string) $avenant->statut),
            'macro_etat' => StatutsDevis::macroEtat((string) $avenant->statut),
            'devis_origine' => [
                'id' => $origine->getKey(),
                'numero' => $origine->numero,
                'titre' => $origine->titre,
                'statut' => (string) $origine->statut,
            ],
        ]), 201);
    }
}
