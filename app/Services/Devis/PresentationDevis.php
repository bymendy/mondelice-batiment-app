<?php

namespace App\Services\Devis;

use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\User;

/**
 * Ce qu'un utilisateur voit d'un devis selon la permission devis.voir_marge.
 *
 * Avec ce droit : prix de revient des lignes, prix d'achat et cout de revient
 * horaire du sous-detail, panneau de marge. Sans lui : rien de tout cela ne
 * sort de l'API (les champs restent masques par les modeles).
 */
final class PresentationDevis
{
    /**
     * @param User|null $utilisateur
     * @return bool
     */
    public static function voitMarge(?User $utilisateur): bool
    {
        return $utilisateur !== null && $utilisateur->can('devis.voir_marge');
    }

    /**
     * Rend visibles les champs de cout d'une ligne et de son sous-detail charge.
     *
     * @param LigneDevis $ligne
     * @param User|null $utilisateur
     * @return LigneDevis
     */
    public static function revelerLigne(LigneDevis $ligne, ?User $utilisateur): LigneDevis
    {
        if (! self::voitMarge($utilisateur)) {
            return $ligne;
        }

        $ligne->makeVisible(['prix_revient_unitaire']);
        if ($ligne->relationLoaded('composants')) {
            $ligne->composants->each->makeVisible(['prix_achat_ht', 'cout_revient_horaire']);
        }

        return $ligne;
    }

    /**
     * @param LigneDevis $ligne
     * @param User|null $utilisateur
     * @return array<string, mixed>
     */
    public static function ligne(LigneDevis $ligne, ?User $utilisateur): array
    {
        return self::revelerLigne($ligne, $utilisateur)->toArray();
    }

    /**
     * Rend visibles les champs de cout de toutes les lignes chargees du devis.
     *
     * @param Devis $devis
     * @param User|null $utilisateur
     * @return void
     */
    public static function reveler(Devis $devis, ?User $utilisateur): void
    {
        if (! $devis->relationLoaded('lignes')) {
            return;
        }

        foreach ($devis->lignes as $ligne) {
            self::revelerLigne($ligne, $utilisateur);
        }
    }

    /**
     * Retire le panneau de marge du detail des totaux sans la permission.
     *
     * @param array<string, mixed> $detail Resultat de CalculTotauxDevis.
     * @param User|null $utilisateur
     * @return array<string, mixed>
     */
    public static function totaux(array $detail, ?User $utilisateur): array
    {
        if (! self::voitMarge($utilisateur)) {
            unset($detail['marge']);
        }

        return $detail;
    }
}
