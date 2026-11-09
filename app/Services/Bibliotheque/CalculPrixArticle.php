<?php

namespace App\Services\Bibliotheque;

use App\Enums\TypeComposant;
use App\Exceptions\QualificationInconnueException;
use App\Models\ArticleComposant;
use App\Models\TauxHoraire;

/**
 * Calcule le prix d'un article a partir de son sous-detail (Bibliotheque de prix).
 *
 * Regles (document d'integration, section 6) :
 *  - prix unitaire = somme (quantite x prix de vente) du sous-detail ; le prix
 *    force, s'il existe, prime ;
 *  - main-d'oeuvre : quantite (heures) x taux de vente de la qualification ;
 *  - prix de revient = somme (quantite x prix d'achat) ; main-d'oeuvre :
 *    quantite x cout de revient de la qualification ;
 *  - marge = prix de vente - prix de revient ;
 *  - coefficient = prix de vente / prix de revient (si le revient est positif).
 *
 * Le calcul est dynamique : un changement de taux horaire change le prix de
 * tous les articles. Il n'a aucun effet sur les devis, dont les lignes sont des
 * copies figees. Rien n'est stocke ni arrondi avant le resultat final.
 *
 * Ce service ne lit pas la base : il recoit les lignes et les taux, ce qui le
 * rend testable sans entreprise.
 */
class CalculPrixArticle
{
    /**
     * Calcule les prix d'un article.
     *
     * @param iterable<ArticleComposant> $composants Lignes du sous-detail (« pour 1 unite »).
     * @param array<string, TauxHoraire> $tauxParCode Taux de l'entreprise indexes par code
     *        (voir TauxHoraire::indexesParCode()).
     * @param float|null $prixForceHt Prix impose a la main, ou null.
     * @return ResultatPrixArticle
     *
     * @throws QualificationInconnueException Une ligne de main-d'oeuvre cite une
     *         qualification absente de $tauxParCode.
     */
    public function calculer(iterable $composants, array $tauxParCode, ?float $prixForceHt = null): ResultatPrixArticle
    {
        $vente = 0.0;
        $revient = 0.0;
        $revientComplet = true;

        foreach ($composants as $composant) {
            $quantite = (float) $composant->quantite;

            if ($composant->type === TypeComposant::MainOeuvre) {
                $code = (string) $composant->qualification;
                $taux = $tauxParCode[$code] ?? null;

                if ($taux === null) {
                    throw new QualificationInconnueException(
                        "Qualification inconnue : « {$code} ». Creez ce taux horaire ou corrigez la ligne."
                    );
                }

                $vente += $quantite * (float) $taux->taux_vente_ht;

                if ($taux->cout_revient_ht === null) {
                    $revientComplet = false;
                } else {
                    $revient += $quantite * (float) $taux->cout_revient_ht;
                }

                continue;
            }

            $vente += $quantite * (float) $composant->prix_vente_ht;

            if ($composant->prix_achat_ht === null) {
                $revientComplet = false;
            } else {
                $revient += $quantite * (float) $composant->prix_achat_ht;
            }
        }

        $venteSousDetail = round($vente, 2);
        $prixVente = $prixForceHt !== null ? round($prixForceHt, 2) : $venteSousDetail;
        $revient = round($revient, 2);

        $marge = null;
        $coefficient = null;
        $tauxMarge = null;

        if ($revientComplet) {
            $marge = round($prixVente - $revient, 2);
            $coefficient = $revient > 0 ? round($prixVente / $revient, 2) : null;
            $tauxMarge = $prixVente > 0 ? round($marge / $prixVente * 100, 2) : null;
        }

        return new ResultatPrixArticle(
            prixVenteHt: $prixVente,
            prixVenteSousDetailHt: $venteSousDetail,
            prixForceHt: $prixForceHt !== null ? round($prixForceHt, 2) : null,
            ecartPrixForceHt: $prixForceHt !== null ? round(round($prixForceHt, 2) - $venteSousDetail, 2) : null,
            prixRevientHt: $revient,
            revientComplet: $revientComplet,
            margeHt: $marge,
            coefficient: $coefficient,
            tauxMargePct: $tauxMarge,
        );
    }
}
