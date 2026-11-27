<?php

namespace App\Http\Controllers;

use App\Models\LigneDevis;
use App\Services\Bibliotheque\NiveauPrestation;
use App\Services\Devis\AccesDevis;
use App\Services\Devis\ControleSeuilsDevis;
use App\Services\Devis\NiveauLigneDevis;
use App\Services\Devis\ParametresDevis;
use App\Services\Devis\PresentationDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Niveau de prestation d'une ligne de devis déjà écrite (tâche 3.6 bis, lot
 * F4c). Permission : devis.modifier, comme pour toute modification de ligne.
 */
class NiveauLigneDevisController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:devis.modifier')];
    }

    /**
     * `niveau_prestation` : `economique`, `standard`, `haut_de_gamme`, ou
     * null pour que la ligne suive de nouveau le niveau du devis.
     *
     * @param Request $request
     * @param int $ligne
     * @param NiveauLigneDevis $service
     * @param ParametresDevis $parametres
     * @return JsonResponse
     */
    public function update(Request $request, int $ligne, NiveauLigneDevis $service, ParametresDevis $parametres): JsonResponse
    {
        $element = LigneDevis::query()->findOrFail($ligne);
        $utilisateur = $request->user();
        $fiche = AccesDevis::visibles($utilisateur)->findOrFail($element->devis_id);
        AccesDevis::exigerModifiable($fiche);

        $donnees = $request->validate([
            'niveau_prestation' => ['present', 'nullable', 'string', Rule::in(NiveauPrestation::NIVEAUX)],
        ]);
        $reglages = $parametres->pour($fiche) ?? $parametres->creer($fiche);
        $avant = ['niveau_prestation' => $element->niveau_prestation, 'niveau_prestation_fixe' => (bool) $element->niveau_prestation_fixe];

        $resultat = app(ControleSeuilsDevis::class)->executer($fiche, $utilisateur, function () use ($service, $element, $reglages, $donnees, $fiche): array {
            $resultat = $service->changer($element, $reglages, $donnees['niveau_prestation']);
            $fiche->recalculerTotaux();

            return $resultat;
        });

        activity()->performedOn($fiche)->causedBy($utilisateur)->event('niveau_ligne_modifie')
            ->withProperties(['ligne_id' => $element->getKey(), 'avant' => $avant, 'apres' => $resultat])
            ->log(sprintf(
                'Niveau de prestation de la ligne « %s » : %s.',
                $element->libelle,
                $resultat['niveau_prestation_fixe']
                    ? 'choisi (' . NiveauPrestation::LIBELLES[$resultat['niveau_prestation']] . ')'
                    : 'suit de nouveau le devis (' . NiveauPrestation::LIBELLES[$resultat['niveau_prestation']] . ')'
            ));

        $fiche->refresh();

        return response()->json(array_merge(
            PresentationDevis::ligne($element->refresh()->load('composants'), $utilisateur),
            [
                'fournitures_changees' => $resultat['fournitures_changees'],
                'totaux_devis' => ['total_ht' => $fiche->total_ht, 'total_tva' => $fiche->total_tva, 'total_ttc' => $fiche->total_ttc],
            ]
        ));
    }
}
