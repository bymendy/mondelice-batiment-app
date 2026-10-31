<?php

namespace App\Http\Controllers;

use App\Models\Parametre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Reglages de l'entreprise (tache 3.4 bis). Reserve aux roles qui ont la
 * permission "parametres.gerer" : Direction et Administratif.
 */
class ParametreController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:parametres.gerer'),
        ];
    }

    /**
     * Liste tous les reglages connus avec leur valeur actuelle.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $reglages = [];

        foreach (Parametre::DEFINITIONS as $cle => $definition) {
            $reglages[] = [
                'cle' => $cle,
                'libelle' => $definition['libelle'],
                'valeur' => Parametre::lire($cle),
                'defaut' => $definition['defaut'],
            ];
        }

        return response()->json($reglages);
    }

    /**
     * Modifie un reglage.
     *
     * @param Request $request Corps : {"valeur": ...}
     * @param string $cle Cle declaree dans Parametre::DEFINITIONS.
     * @return JsonResponse
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException 404 si la cle est inconnue.
     * @throws \Illuminate\Validation\ValidationException 422 si la valeur ne respecte pas la regle du reglage.
     */
    public function update(Request $request, string $cle): JsonResponse
    {
        abort_unless(array_key_exists($cle, Parametre::DEFINITIONS), 404);

        $donnees = $request->validate([
            'valeur' => ['required', Parametre::DEFINITIONS[$cle]['regle']],
        ]);

        $parametre = Parametre::ecrire($cle, $donnees['valeur']);

        return response()->json([
            'cle' => $cle,
            'valeur' => $parametre->valeur,
        ]);
    }
}
