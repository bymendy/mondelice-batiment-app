<?php

namespace App\Services\Devis;

use App\Models\DevisParametres;
use App\Models\Fourniture;
use App\Models\LigneDevis;
use App\Services\Bibliotheque\Arrondi;
use App\Services\Bibliotheque\NiveauPrestation;
use App\Services\Bibliotheque\PrixVenteFourniture;
use Illuminate\Validation\ValidationException;

/**
 * Change le niveau de prestation d'une ligne de devis déjà écrite (tâche
 * 3.6 bis, lot F4c).
 *
 *  - Un niveau donné : la ligne le prend et le garde (« choisi à la main »,
 *    le recalcul du devis ne le change plus).
 *  - Aucun niveau (null) : la ligne suit de nouveau le niveau du devis.
 *
 * Seules les fournitures qui ont une variante changent ; la main-d'œuvre
 * n'est pas touchée. Une fourniture qui change de gamme prend le prix actuel
 * de la Base de fournitures (c'est un autre produit). Une ligne à prix forcé
 * garde son prix, plus l'écart de prix de vente des fournitures remplacées.
 */
class NiveauLigneDevis
{
    /**
     * @param LigneDevis $ligne Ligne d'un devis modifiable.
     * @param DevisParametres $parametres Paramètres de ce devis.
     * @param string|null $niveau Niveau choisi, ou null pour suivre le devis.
     * @return array{niveau_prestation: string, niveau_prestation_fixe: bool, fournitures_changees: int, prix_avant: float, prix_apres: float}
     *
     * @throws ValidationException Si la ligne n'a pas de sous-détail.
     */
    public function changer(LigneDevis $ligne, DevisParametres $parametres, ?string $niveau): array
    {
        $ligne->loadMissing('composants');
        if ($ligne->composants->isEmpty()) {
            throw ValidationException::withMessages([
                'niveau_prestation' => 'Cette ligne ne vient pas de la bibliothèque : elle n\'a pas de niveau de prestation.',
            ]);
        }

        $fixe = $niveau !== null;
        $cible = $niveau ?? (string) ($parametres->niveau_prestation ?: NiveauPrestation::STANDARD);
        $colonne = NiveauPrestation::COLONNES[$cible] ?? null;

        $ids = [];
        foreach ($ligne->composants as $composant) {
            foreach (['fourniture_standard_id', 'fourniture_eco_id', 'fourniture_haut_id'] as $champ) {
                if ($composant->{$champ} !== null) {
                    $ids[(int) $composant->{$champ}] = true;
                }
            }
        }
        $fournitures = $ids === [] ? collect() : Fourniture::withTrashed()->whereIn('id', array_keys($ids))->get()->keyBy('id');

        $marge = $parametres->marge_fournitures;
        $fraisPct = (float) $parametres->frais_generaux_fournitures;
        $prixAvant = (float) $ligne->prix_unitaire_ht;
        $venteAvant = $this->vente($ligne);
        $changees = 0;

        foreach ($ligne->composants as $composant) {
            if ($composant->type !== 'fourniture_base' || $composant->fourniture_standard_id === null) {
                continue;
            }
            $voulue = (int) (($colonne !== null ? $composant->{$colonne} : null) ?? $composant->fourniture_standard_id);
            $produit = $voulue !== (int) $composant->fourniture_id ? $fournitures->get($voulue) : null;
            if ($produit === null) {
                continue;
            }

            $calcule = ! $produit->prix_vente_saisi;
            $vente = $produit->prix_vente_ht;
            // Une fourniture à prix calculé suit la marge et les frais généraux du devis.
            if ($calcule && $marge !== null && $produit->prix_achat_ht !== null) {
                $vente = PrixVenteFourniture::calculer((float) $produit->prix_achat_ht, (float) $marge, $fraisPct);
            }
            $composant->fill([
                'fourniture_id' => $voulue,
                'designation' => $produit->designation,
                'prix_achat_ht' => $produit->prix_achat_ht,
                'prix_vente_ht' => $vente,
                'prix_vente_calcule' => $calcule,
            ]);
            $composant->save();
            $changees++;
        }

        $venteApres = $this->vente($ligne);
        if ($changees > 0) {
            // Ligne à prix forcé : son prix suit l'écart des fournitures remplacées, sans descendre sous zéro.
            $ligne->prix_unitaire_ht = $ligne->prix_force
                ? max(0, Arrondi::euros((int) round($prixAvant * 100) + $venteApres - $venteAvant))
                : Arrondi::euros($venteApres);
            $ligne->prix_revient_unitaire = $this->revient($ligne, 1 + $fraisPct / 100);
        }
        $ligne->niveau_prestation = $cible;
        $ligne->niveau_prestation_fixe = $fixe;
        $ligne->save();

        return [
            'niveau_prestation' => $cible,
            'niveau_prestation_fixe' => $fixe,
            'fournitures_changees' => $changees,
            'prix_avant' => $prixAvant,
            'prix_apres' => (float) $ligne->prix_unitaire_ht,
        ];
    }

    /**
     * Prix de vente du sous-détail, en centimes (chaque ligne arrondie au centime).
     *
     * @param LigneDevis $ligne
     * @return int
     */
    private function vente(LigneDevis $ligne): int
    {
        $centimes = 0;
        foreach ($ligne->composants as $composant) {
            $centimes += Arrondi::ligneEnCentimes($composant->quantite, $composant->prix_vente_ht ?? 0);
        }

        return $centimes;
    }

    /**
     * Prix de revient du sous-détail, ou null s'il manque un prix d'achat.
     * Les frais généraux s'ajoutent au revient des fournitures.
     *
     * @param LigneDevis $ligne
     * @param float $coefficientFrais
     * @return float|null
     */
    private function revient(LigneDevis $ligne, float $coefficientFrais): ?float
    {
        $centimes = 0;
        foreach ($ligne->composants as $composant) {
            if ($composant->prix_achat_ht === null) {
                return null;
            }
            $centimes += Arrondi::ligneEnCentimes(
                $composant->quantite,
                in_array($composant->type, ['fourniture_base', 'fourniture_libre'], true)
                    ? (float) $composant->prix_achat_ht * $coefficientFrais
                    : $composant->prix_achat_ht
            );
        }

        return Arrondi::euros($centimes);
    }
}
