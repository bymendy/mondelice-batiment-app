<?php

namespace App\Services\Bibliotheque;

use App\Models\Fournisseur;
use App\Models\Fourniture;
use Illuminate\Validation\ValidationException;

/**
 * Prix d'une fourniture de la base (lot F1, décision du 23 novembre 2026).
 *
 *  - Avec un prix tarif : achat net = tarif x (1 - remise). La remise est
 *    celle de la fourniture, sinon celle par défaut du fournisseur, sinon 0.
 *    Le prix d'achat ne se saisit alors pas : il est calculé.
 *  - Sans prix tarif : le prix d'achat se saisit (ou reste inconnu).
 *  - Prix de vente : saisi, sinon calculé depuis le prix d'achat avec la
 *    marge par défaut des fournitures (PrixVenteFourniture).
 */
final class TarifFourniture
{
    /**
     * @param PrixVenteFourniture $prixVente
     */
    public function __construct(private readonly PrixVenteFourniture $prixVente)
    {
    }

    /**
     * Remise appliquée à une fourniture, en %.
     *
     * @param mixed $remiseFourniture
     * @param Fournisseur|null $fournisseur
     * @return float
     */
    public static function remiseAppliquee(mixed $remiseFourniture, ?Fournisseur $fournisseur): float
    {
        if ($remiseFourniture !== null && $remiseFourniture !== '') {
            return (float) $remiseFourniture;
        }

        return $fournisseur !== null ? (float) $fournisseur->remise_defaut_pct : 0.0;
    }

    /**
     * @param float|null $tarif Prix tarif HT.
     * @param mixed $remise Remise de la fourniture (NULL = celle du fournisseur).
     * @param mixed $achatSaisi Prix d'achat saisi (seulement sans prix tarif).
     * @param mixed $venteSaisie Prix de vente saisi (NULL = à calculer).
     * @param Fournisseur|null $fournisseur
     * @return array{prix_achat_ht: float|null, prix_vente_ht: float, prix_vente_saisi: bool}
     *
     * @throws ValidationException Si les prix se contredisent ou manquent.
     */
    public function calculer(?float $tarif, mixed $remise, mixed $achatSaisi, mixed $venteSaisie, ?Fournisseur $fournisseur): array
    {
        $achatDonne = $achatSaisi !== null && $achatSaisi !== '';

        if ($tarif !== null) {
            if ($achatDonne) {
                throw ValidationException::withMessages([
                    'prix_achat_ht' => 'Avec un prix tarif, le prix d\'achat net est calculé (tarif moins la remise) : ne le saisissez pas.',
                ]);
            }
            $achat = round($tarif * (1 - self::remiseAppliquee($remise, $fournisseur) / 100), 4);
        } else {
            $achat = $achatDonne ? round((float) $achatSaisi, 4) : null;
        }

        $saisi = $venteSaisie !== null && $venteSaisie !== '';
        $vente = $this->prixVente->resoudre($saisi ? $venteSaisie : null, $achat);

        return [
            'prix_achat_ht' => $achat,
            'prix_vente_ht' => round((float) $vente, 4),
            'prix_vente_saisi' => $saisi,
        ];
    }

    /**
     * Recalcule une fourniture enregistrée (par exemple quand la remise de
     * son fournisseur change). Ne l'enregistre pas.
     *
     * @param Fourniture $fourniture
     * @param Fournisseur|null $fournisseur
     * @return array{prix_achat_ht: float|null, prix_vente_ht: float, prix_vente_saisi: bool}
     */
    public function recalculer(Fourniture $fourniture, ?Fournisseur $fournisseur): array
    {
        $tarif = $fourniture->prix_tarif_ht !== null ? (float) $fourniture->prix_tarif_ht : null;

        return $this->calculer(
            $tarif,
            $fourniture->remise_pct,
            $tarif === null ? $fourniture->prix_achat_ht : null,
            $fourniture->prix_vente_saisi ? $fourniture->prix_vente_ht : null,
            $fournisseur
        );
    }
}
