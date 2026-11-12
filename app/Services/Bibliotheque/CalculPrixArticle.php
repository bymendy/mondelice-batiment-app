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
 *  - le montant d'une ligne est arrondi au centime (quantite x prix unitaire),
 *    puis les lignes sont additionnees : le total est toujours la somme des
 *    montants affiches (voir Arrondi, lot 8) ;
 *  - prix unitaire de l'article = somme des montants de vente du sous-detail ;
 *    le prix force, s'il existe, prime ;
 *  - main-d'oeuvre : quantite (heures) x taux de vente de la qualification ;
 *  - prix de revient = somme des montants de revient (quantite x prix d'achat ;
 *    main-d'oeuvre : quantite x cout de revient de la qualification) ;
 *  - marge = prix de vente - prix de revient ;
 *  - coefficient = prix de vente / prix de revient (si le revient est positif) ;
 *  - pose seule = le sous-detail sans les fournitures (main-d'oeuvre, materiel
 *    et frais), pour chiffrer un travail dont la fourniture est a la charge du
 *    client. Le prix force n'y change rien.
 *
 * Le calcul est dynamique : un changement de taux horaire change le prix de
 * tous les articles. Il n'a aucun effet sur les devis, dont les lignes sont des
 * copies figees. Rien n'est stocke.
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
        // Tout est compte en centimes entiers : les montants de ligne sont deja arrondis.
        $venteCts = 0;
        $revientCts = 0;
        $revientComplet = true;
        $poseCts = 0;
        $revientPoseCts = 0;
        $revientPoseComplet = true;

        foreach ($composants as $composant) {
            $quantite = $composant->quantite;
            $estFourniture = in_array($composant->type, [TypeComposant::FournitureLibre, TypeComposant::FournitureBase], true);

            if ($composant->type === TypeComposant::MainOeuvre) {
                $code = (string) $composant->qualification;
                $taux = $tauxParCode[$code] ?? null;

                if ($taux === null) {
                    throw new QualificationInconnueException(
                        "Qualification inconnue : « {$code} ». Creez ce taux horaire ou corrigez la ligne."
                    );
                }

                $venteLigne = Arrondi::ligneEnCentimes($quantite, $taux->taux_vente_ht);
                $revientLigne = $taux->cout_revient_ht === null
                    ? null
                    : Arrondi::ligneEnCentimes($quantite, $taux->cout_revient_ht);
            } else {
                $venteLigne = Arrondi::ligneEnCentimes($quantite, $composant->prix_vente_ht);
                $revientLigne = $composant->prix_achat_ht === null
                    ? null
                    : Arrondi::ligneEnCentimes($quantite, $composant->prix_achat_ht);
            }

            $venteCts += $venteLigne;

            if ($revientLigne === null) {
                $revientComplet = false;
            } else {
                $revientCts += $revientLigne;
            }

            if (! $estFourniture) {
                $poseCts += $venteLigne;

                if ($revientLigne === null) {
                    $revientPoseComplet = false;
                } else {
                    $revientPoseCts += $revientLigne;
                }
            }
        }

        $forceCts = $prixForceHt !== null ? (int) round($prixForceHt * 100) : null;
        $prixVenteCts = $forceCts ?? $venteCts;

        $marge = null;
        $coefficient = null;
        $tauxMarge = null;

        if ($revientComplet) {
            $margeCts = $prixVenteCts - $revientCts;
            $marge = Arrondi::euros($margeCts);
            $coefficient = $revientCts > 0 ? round($prixVenteCts / $revientCts, 2) : null;
            $tauxMarge = $prixVenteCts > 0 ? round($margeCts / $prixVenteCts * 100, 2) : null;
        }

        return new ResultatPrixArticle(
            prixVenteHt: Arrondi::euros($prixVenteCts),
            prixVenteSousDetailHt: Arrondi::euros($venteCts),
            prixForceHt: $forceCts !== null ? Arrondi::euros($forceCts) : null,
            ecartPrixForceHt: $forceCts !== null ? Arrondi::euros($forceCts - $venteCts) : null,
            prixRevientHt: Arrondi::euros($revientCts),
            revientComplet: $revientComplet,
            margeHt: $marge,
            coefficient: $coefficient,
            tauxMargePct: $tauxMarge,
            prixPoseSeuleHt: Arrondi::euros($poseCts),
            prixRevientPoseSeuleHt: $revientPoseComplet ? Arrondi::euros($revientPoseCts) : null,
        );
    }
}
