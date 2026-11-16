<?php

namespace App\Services\Devis;

use App\Enums\TypeComposant;
use App\Exceptions\QualificationInconnueException;
use App\Models\ArticlePrix;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\LigneDevisComposant;
use App\Models\TauxHoraire;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copie figee d'un article de la bibliotheque dans une ligne de devis.
 *
 * Le prix est calcule une seule fois, a cet instant, avec les taux horaires
 * de l'entreprise (un prix force de l'article prime sur le sous-detail). La
 * ligne et son sous-detail ne sont plus jamais recalcules depuis la
 * bibliotheque : une modification de l'article ou d'un taux horaire ne change
 * donc aucun devis deja redige.
 */
class CopieArticleDansDevis
{
    /**
     * @param Devis $devis Devis (brouillon) qui recoit la ligne.
     * @param ArticlePrix $article Article visible de l'utilisateur.
     * @param float $quantite Quantite de la ligne (negative = deduction).
     * @param int|null $lotId Lot du devis, facultatif.
     * @param int|null $emplacementId Emplacement du devis, facultatif.
     * @return LigneDevis La ligne creee, avec son sous-detail.
     *
     * @throws ValidationException Si le prix de l'article ne peut pas etre calcule.
     */
    public function copier(
        Devis $devis,
        ArticlePrix $article,
        float $quantite,
        ?int $lotId = null,
        ?int $emplacementId = null
    ): LigneDevis {
        $article->loadMissing('composants');
        $taux = TauxHoraire::indexesParCode();

        try {
            $resultat = $article->calculerPrix($taux);
        } catch (QualificationInconnueException $e) {
            throw ValidationException::withMessages(['article_prix_id' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($devis, $article, $quantite, $lotId, $emplacementId, $resultat, $taux): LigneDevis {
            $ligne = LigneDevis::create([
                'devis_id' => $devis->getKey(),
                'lot_id' => $lotId,
                'emplacement_id' => $emplacementId,
                'article_prix_id' => $article->getKey(),
                'libelle' => $article->designation,
                'descriptif' => $article->description,
                'dtu_reference' => $article->dtu_reference,
                'quantite' => $quantite,
                'unite' => $article->unite->value,
                'prix_unitaire_ht' => $resultat->prixVenteHt,
                'prix_revient_unitaire' => $resultat->revientComplet ? $resultat->prixRevientHt : null,
                'taux_tva' => $article->taux_tva,
                'ordre' => ((int) LigneDevis::query()->where('devis_id', $devis->getKey())->max('ordre')) + 1,
            ]);

            $rang = 0;
            foreach ($article->composants as $composant) {
                $estMainOeuvre = $composant->type === TypeComposant::MainOeuvre;
                $tauxHoraire = $estMainOeuvre ? $taux[(string) $composant->qualification] : null;

                LigneDevisComposant::create([
                    'ligne_devis_id' => $ligne->getKey(),
                    'type' => $composant->type->value,
                    'designation' => $composant->designation,
                    'quantite' => $composant->quantite,
                    'unite' => $composant->unite,
                    'prix_achat_ht' => $estMainOeuvre ? $tauxHoraire->cout_revient_ht : $composant->prix_achat_ht,
                    'prix_vente_ht' => $estMainOeuvre ? $tauxHoraire->taux_vente_ht : $composant->prix_vente_ht,
                    'taux_horaire_libelle' => $estMainOeuvre ? $tauxHoraire->libelle : null,
                    'taux_horaire_vente' => $estMainOeuvre ? $tauxHoraire->taux_vente_ht : null,
                    'cout_revient_horaire' => $estMainOeuvre ? $tauxHoraire->cout_revient_ht : null,
                    'ordre' => ++$rang,
                ]);
            }

            return $ligne->load('composants');
        });
    }
}
