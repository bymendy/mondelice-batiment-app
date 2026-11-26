<?php

namespace App\Http\Controllers;

use App\Models\Fournisseur;
use App\Models\Fourniture;
use App\Services\Import\EcrivainTableur;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export des fournitures d'un fournisseur (tâche 3.6 bis, lot F3b), pour les
 * modifier dans un tableur puis les réimporter. Permission :
 * bibliotheque.importer (le fichier contient les prix d'achat).
 *
 * Les en-têtes sont ceux que l'import reconnaît : le fichier se réimporte
 * sans choisir les colonnes. Une seule colonne de prix sert à l'import :
 *  - `prix=tarif` : « Prix tarif HT » et « Remise % » ; le coût après remise
 *    est donné pour information (colonne non reprise à l'import) ;
 *  - `prix=achat` : « Prix achat net HT ».
 * Sans ce paramètre : `tarif` si au moins une fourniture a un prix tarif.
 */
class ExportFournituresController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:bibliotheque.importer')];
    }

    /**
     * Paramètres : `fournisseur_id` (obligatoire), `format` (`xlsx` par
     * défaut, ou `csv`), `prix` (`tarif` ou `achat`), `actif` (booléen).
     *
     * @param Request $request
     * @param EcrivainTableur $ecrivain
     * @return Response
     */
    public function __invoke(Request $request, EcrivainTableur $ecrivain): Response
    {
        $donnees = $request->validate([
            'fournisseur_id' => ['required', 'integer'],
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
            'prix' => ['nullable', Rule::in(['tarif', 'achat'])],
            'actif' => ['nullable', 'boolean'],
        ]);

        $fournisseur = Fournisseur::query()->find((int) $donnees['fournisseur_id']);
        if ($fournisseur === null) {
            throw ValidationException::withMessages(['fournisseur_id' => 'Ce fournisseur n\'existe pas dans l\'entreprise.']);
        }

        $fournitures = Fourniture::query()
            ->where('fournisseur_id', $fournisseur->getKey())
            ->when($request->filled('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('reference')->orderBy('id')
            ->get();

        $base = $donnees['prix'] ?? ($fournitures->contains(fn (Fourniture $f): bool => $f->prix_tarif_ht !== null) ? 'tarif' : 'achat');
        $nombre = static fn (mixed $valeur): ?float => $valeur === null ? null : (float) $valeur;

        $lignes = [$base === 'tarif'
            ? ['Référence', 'Désignation', 'Unité', 'Prix tarif HT', 'Remise %', 'Prix de vente HT', 'Coût après remise (information)', 'Date catalogue']
            : ['Référence', 'Désignation', 'Unité', 'Prix achat net HT', 'Prix de vente HT', 'Date catalogue']];

        foreach ($fournitures as $fourniture) {
            $debut = [(string) $fourniture->reference, (string) $fourniture->designation, (string) $fourniture->unite];
            $date = $fourniture->date_tarif?->format('Y-m-d');
            $lignes[] = $base === 'tarif'
                ? [...$debut, $nombre($fourniture->prix_tarif_ht), $nombre($fourniture->remise_pct), $nombre($fourniture->prix_vente_ht), $nombre($fourniture->prix_achat_ht), $date]
                : [...$debut, $nombre($fourniture->prix_achat_ht), $nombre($fourniture->prix_vente_ht), $date];
        }

        $format = $donnees['format'] ?? 'xlsx';
        $contenu = $format === 'csv' ? $ecrivain->csv($lignes) : $ecrivain->xlsx($lignes);
        $nom = sprintf('fournitures-%s-%s.%s', Str::slug($fournisseur->nom) ?: 'fournisseur', now()->format('Y-m-d'), $format);

        activity()->performedOn($fournisseur)->causedBy($request->user())->event('fournitures_exportees')
            ->withProperties(['fournisseur_id' => $fournisseur->getKey(), 'format' => $format, 'prix' => $base, 'fournitures' => $fournitures->count()])
            ->log(sprintf('Fournitures de %s exportées (%d).', $fournisseur->nom, $fournitures->count()));

        return response($contenu, 200, [
            'Content-Type' => $format === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $nom . '"',
            'X-Colonne-Prix' => $base,
        ]);
    }
}
