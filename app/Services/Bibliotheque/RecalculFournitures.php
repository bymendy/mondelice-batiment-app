<?php

namespace App\Services\Bibliotheque;

use App\Models\Fourniture;
use App\Models\Parametre;
use Illuminate\Support\Facades\DB;

/**
 * Recalcul des fournitures à prix calculé (lot F2a, décision du 23 novembre 2026).
 *
 * Quand les frais généraux ou la marge par défaut des fournitures changent,
 * les fournitures dont le prix de vente est CALCULÉ (pas saisi) suivent la
 * formule vente = achat x (1 + frais) / (1 - marge), puis les articles qui
 * les utilisent sont mis à jour. Un prix de vente saisi ne bouge pas. Les
 * devis déjà écrits non plus.
 *
 * Sans marge par défaut, rien n'est recalculé : les prix restent en l'état.
 */
final class RecalculFournitures
{
    /**
     * @return array{fournitures: int, articles: int}
     */
    public static function apresChangementDeReglage(): array
    {
        $bilan = ['fournitures' => 0, 'articles' => 0];
        $marge = Parametre::lire('marge_fournitures_defaut');
        if ($marge === null) {
            return $bilan;
        }
        $frais = PrixVenteFourniture::fraisGenerauxPct();

        DB::transaction(function () use (&$bilan, $marge, $frais): void {
            $fournitures = Fourniture::query()->where('prix_vente_saisi', false)->whereNotNull('prix_achat_ht')->get();
            foreach ($fournitures as $fourniture) {
                $fourniture->prix_vente_ht = PrixVenteFourniture::calculer((float) $fourniture->prix_achat_ht, (float) $marge, $frais);
                if (! $fourniture->isDirty()) {
                    continue;
                }
                $fourniture->save();
                $bilan['fournitures']++;
                $bilan['articles'] += SynchronisationFournitures::propager($fourniture);
            }

            if ($bilan['fournitures'] > 0) {
                activity()->causedBy(auth()->user())->event('fournitures_recalculees')
                    ->withProperties($bilan + ['marge_pct' => (float) $marge, 'frais_generaux_pct' => $frais])
                    ->log(sprintf('%d fourniture(s) à prix calculé recalculée(s).', $bilan['fournitures']));
            }
        });

        return $bilan;
    }
}
