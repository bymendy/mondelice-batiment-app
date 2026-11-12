<?php

namespace App\Http\Controllers;

use App\Enums\TypeSeuil;
use App\Models\Parametre;
use App\Models\SeuilTarifaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

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

        // `present` plutot que `required` : un reglage facultatif peut etre remis a null.
        $regle = (string) Parametre::DEFINITIONS[$cle]['regle'];
        $regles = array_merge([str_contains($regle, 'nullable') ? 'present' : 'required'], explode('|', $regle));
        $donnees = $request->validate(['valeur' => $regles]);

        $this->verifierMargeFournitures($request, $cle, $donnees['valeur']);

        $parametre = Parametre::ecrire($cle, $donnees['valeur']);

        return response()->json([
            'cle' => $cle,
            'valeur' => $parametre->valeur,
        ]);
    }

    /**
     * La marge par defaut des fournitures ne peut pas descendre sous le seuil
     * actif de la Direction, sauf pour qui a `parametres.seuils` (la Direction).
     *
     * @param Request $request
     * @param string $cle
     * @param mixed $valeur
     * @return void
     *
     * @throws ValidationException 422 si la valeur est sous le seuil.
     */
    private function verifierMargeFournitures(Request $request, string $cle, mixed $valeur): void
    {
        if ($cle !== 'marge_fournitures_defaut' || $valeur === null || $request->user()->can('parametres.seuils')) {
            return;
        }

        $seuil = SeuilTarifaire::actifsParType()[TypeSeuil::MargeFournitures->value] ?? null;

        if ($seuil !== null && (float) $valeur + 0.000001 < $seuil) {
            throw ValidationException::withMessages([
                'valeur' => 'La marge par défaut ne peut pas être inférieure au seuil fixé par la Direction (' . rtrim(rtrim(number_format($seuil, 2, ',', ''), '0'), ',') . ' %).',
            ]);
        }
    }
}
