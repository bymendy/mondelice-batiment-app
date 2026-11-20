<?php

namespace App\Services\Devis;

use App\Models\Devis;
use App\Models\User;

/**
 * Le marché d'un devis : le devis d'origine et ses avenants (lot D5, décisions
 * du 20 novembre 2026).
 *
 * Montant cumulé du marché = devis d'origine accepté + avenants acceptés. Les
 * avenants en cours (en rédaction ou envoyés) sont comptés à part ; les
 * avenants refusés, expirés ou annulés ne comptent pas.
 */
final class MarcheDevis
{
    /**
     * Ce que la fiche d'un devis ajoute sur son marché.
     *
     * @param Devis $devis
     * @param User|null $utilisateur
     * @return array<string, mixed> Pour un devis d'origine : `avenants` et `marche`.
     *                              Pour un avenant : `devis_origine`.
     */
    public static function pour(Devis $devis, ?User $utilisateur): array
    {
        if ($devis->devis_parent_id !== null) {
            $origine = self::visibles($utilisateur)->find($devis->devis_parent_id);

            return [
                'devis_origine' => $origine === null ? null : [
                    'id' => $origine->getKey(),
                    'numero' => $origine->numero,
                    'titre' => $origine->titre,
                    'statut' => (string) $origine->statut,
                ],
            ];
        }

        $avenants = self::visibles($utilisateur)
            ->where('devis_parent_id', $devis->getKey())
            ->orderBy('rang_avenant')
            ->get();

        $acceptes = $avenants->filter(fn (Devis $avenant): bool => (string) $avenant->statut === 'accepte');
        $enCours = $avenants->filter(fn (Devis $avenant): bool => in_array(
            (string) $avenant->statut,
            array_merge(StatutsDevis::BROUILLON, StatutsDevis::ENVOYE),
            true
        ));
        $origineAcceptee = (string) $devis->statut === 'accepte';
        $origineHt = $origineAcceptee ? self::centimes($devis->total_ht) : 0;
        $origineTtc = $origineAcceptee ? self::centimes($devis->total_ttc) : 0;

        return [
            'avenants' => $avenants->map(fn (Devis $avenant): array => [
                'id' => $avenant->getKey(),
                'numero' => $avenant->numero,
                'rang_avenant' => $avenant->rang_avenant,
                'titre' => $avenant->titre,
                'statut' => (string) $avenant->statut,
                'statut_libelle' => StatutsDevis::libelle((string) $avenant->statut),
                'total_ht' => self::euros(self::centimes($avenant->total_ht)),
                'total_ttc' => self::euros(self::centimes($avenant->total_ttc)),
            ])->values()->all(),
            'marche' => [
                'devis_accepte' => $origineAcceptee,
                'devis_ht' => self::euros($origineHt),
                'devis_ttc' => self::euros($origineTtc),
                'avenants_acceptes' => self::somme($acceptes),
                'avenants_en_cours' => self::somme($enCours),
                'cumul_ht' => self::euros($origineHt + self::total($acceptes, 'total_ht')),
                'cumul_ttc' => self::euros($origineTtc + self::total($acceptes, 'total_ttc')),
            ],
        ];
    }

    /**
     * @param User|null $utilisateur
     * @return \Illuminate\Database\Eloquent\Builder<Devis>
     */
    private static function visibles(?User $utilisateur)
    {
        return $utilisateur === null ? Devis::query() : AccesDevis::visibles($utilisateur);
    }

    /**
     * @param \Illuminate\Support\Collection<int, Devis> $avenants
     * @return array{nombre: int, ht: float, ttc: float}
     */
    private static function somme($avenants): array
    {
        return [
            'nombre' => $avenants->count(),
            'ht' => self::euros(self::total($avenants, 'total_ht')),
            'ttc' => self::euros(self::total($avenants, 'total_ttc')),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, Devis> $avenants
     * @param string $colonne
     * @return int Total en centimes.
     */
    private static function total($avenants, string $colonne): int
    {
        return (int) $avenants->sum(fn (Devis $avenant): int => self::centimes($avenant->{$colonne}));
    }

    /**
     * @param mixed $montant
     * @return int
     */
    private static function centimes(mixed $montant): int
    {
        return (int) round(((float) $montant) * 100);
    }

    /**
     * @param int $centimes
     * @return float
     */
    private static function euros(int $centimes): float
    {
        return round($centimes / 100, 2);
    }
}
