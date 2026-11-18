<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangerStatutDevisRequest;
use App\Models\Devis;
use App\Services\Devis\AccesDevis;
use App\Services\Devis\ControleSeuilsDevis;
use App\Services\Devis\ChangementStatutDevis;
use App\Services\Devis\StatutsDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Envoi d'un devis et changements manuels de statut.
 */
class DevisStatutController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:devis.envoyer', only: ['envoyer']),
            new Middleware('can:devis.changer_statut_manuel', only: ['changer']),
        ];
    }

    /**
     * Envoie le devis : action humaine explicite. Contrôles : le passage doit
     * être permis et le devis doit avoir au moins une ligne. L'envoi recalcule
     * et fige les totaux, pose la date d'envoi et fait passer l'affaire à
     * « Devis envoyé ».
     *
     * @param Request $request
     * @param ChangementStatutDevis $changement
     * @param int $devis
     * @return JsonResponse
     *
     * @throws ValidationException Si le devis ne peut pas être envoyé.
     */
    public function envoyer(Request $request, ChangementStatutDevis $changement, int $devis): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);

        $changement->verifier($fiche, 'envoye');
        if (! $fiche->lignes()->exists()) {
            throw ValidationException::withMessages([
                'lignes' => 'Ajoutez au moins une ligne avant d\'envoyer le devis.',
            ]);
        }

        app(ControleSeuilsDevis::class)->verifierAvantEnvoi($fiche, $request->user());

        $changement->appliquer($fiche, 'envoye', $request->user());

        return response()->json($this->reponse($fiche));
    }

    /**
     * Change le statut à la main : négociation, accepté, refusé, annulé.
     *
     * @param ChangerStatutDevisRequest $request
     * @param ChangementStatutDevis $changement
     * @param int $devis
     * @return JsonResponse
     *
     * @throws ValidationException Si le passage n'est pas permis.
     */
    public function changer(ChangerStatutDevisRequest $request, ChangementStatutDevis $changement, int $devis): JsonResponse
    {
        $fiche = AccesDevis::visibles($request->user())->findOrFail($devis);
        $donnees = $request->validated();

        $changement->appliquer($fiche, $donnees['statut'], $request->user(), Arr::only($donnees, ['motif', 'preuve_acceptation']));

        return response()->json($this->reponse($fiche));
    }

    /**
     * @param Devis $devis
     * @return array<string, mixed>
     */
    private function reponse(Devis $devis): array
    {
        $devis->refresh();

        return array_merge($devis->toArray(), [
            'statut_libelle' => StatutsDevis::libelle((string) $devis->statut),
            'macro_etat' => StatutsDevis::macroEtat((string) $devis->statut),
        ]);
    }
}
