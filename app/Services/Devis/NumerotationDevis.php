<?php

namespace App\Services\Devis;

use App\Models\Devis;
use Illuminate\Support\Facades\DB;

/**
 * Numero d'un devis : « DE » + annee + tiret + rang sur 4 chiffres
 * (DE2026-0036), attribue des la creation du brouillon.
 *
 * La suite est propre a chaque entreprise et a chaque annee. Un numero n'est
 * jamais reutilise : les devis archives comptent dans le calcul. Un verrou de
 * transaction evite que deux creations simultanees prennent le meme numero ;
 * l'index unique de la base reste le dernier garde-fou.
 */
class NumerotationDevis
{
    /**
     * Attribue et enregistre le numero du devis.
     *
     * @param Devis $devis Un devis deja enregistre (son entreprise est connue).
     * @return string Le numero attribue.
     */
    public function attribuer(Devis $devis): string
    {
        return DB::transaction(function () use ($devis): string {
            $annee = ($devis->date_devis ?? now())->format('Y');
            $prefixe = 'DE' . $annee . '-';

            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$devis->tenant_id . '|devis|' . $annee]);

            $dernier = Devis::withTrashed()
                ->whereNull('devis_parent_id')
                ->where('numero', 'like', $prefixe . '%')
                ->max(DB::raw('CAST(substring(numero from \'-([0-9]+)$\') AS integer)'));

            $numero = $prefixe . str_pad((string) (((int) $dernier) + 1), 4, '0', STR_PAD_LEFT);

            $devis->forceFill(['numero' => $numero])->save();

            return $numero;
        });
    }
}
