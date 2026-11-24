<?php

namespace App\Services\Devis;

use App\Enums\TypeComposant;
use App\Exceptions\QualificationInconnueException;
use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\Devis;
use App\Models\LigneDevis;
use App\Models\LigneDevisComposant;
use App\Services\Bibliotheque\CalculPrixArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copie figee d'un article de la bibliotheque dans une ligne de devis.
 *
 * Le prix est calcule une seule fois, a cet instant, avec les PARAMETRES DU
 * DEVIS (decisions du 20 novembre 2026) : ses taux horaires, son coefficient
 * de difficulte (qui multiplie le temps de main-d'oeuvre) et son coefficient
 * de zone (1,00 pour l'instant). Un prix force de l'article prime sur le
 * sous-detail : la ligne est alors marquee `prix_force`.
 *
 * La ligne et son sous-detail ne sont plus jamais recalcules depuis la
 * bibliotheque : une modification de l'article, d'un taux horaire de
 * l'entreprise ou des parametres du devis ne change aucune ligne deja ecrite.
 *
 * Le sous-detail garde le temps de base (`quantite_base`) et la qualification
 * de chaque ligne de main-d'oeuvre : c'est ce qui permettra de recalculer.
 */
class CopieArticleDansDevis
{
    /**
     * @param ParametresDevis $parametres
     */
    public function __construct(private ParametresDevis $parametres)
    {
    }

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
        $origines = $article->composants->values();

        $parametres = $this->parametres->pour($devis) ?? $this->parametres->creer($devis);
        $difficulte = (float) $parametres->coefficient_difficulte;

        $codes = $origines
            ->filter(fn (ArticleComposant $composant): bool => $composant->type === TypeComposant::MainOeuvre)
            ->map(fn (ArticleComposant $composant): string => (string) $composant->qualification)
            ->unique()
            ->all();
        $taux = $this->parametres->tauxPourCalcul($devis, $parametres, $codes);

        // Sous-detail ajuste : la difficulte multiplie le temps de main-d'oeuvre.
        // Lot F2b : une fourniture de la base a prix CALCULE suit la marge et les frais generaux du devis.
        $calculees = $this->fournituresAPrixCalcule($origines);
        $margeDevis = $parametres->marge_fournitures;
        $fraisDevis = (float) $parametres->frais_generaux_fournitures;

        $ajustes = $origines->map(function (ArticleComposant $composant) use ($difficulte, $calculees, $margeDevis, $fraisDevis): ArticleComposant {
            $copie = clone $composant;
            if ($composant->type === TypeComposant::MainOeuvre) {
                $copie->quantite = round((float) $composant->quantite * $difficulte, 4);
            } elseif ($margeDevis !== null && $composant->prix_achat_ht !== null && isset($calculees[(int) $composant->fourniture_id])) {
                $copie->prix_vente_ht = \App\Services\Bibliotheque\PrixVenteFourniture::calculer((float) $composant->prix_achat_ht, (float) $margeDevis, $fraisDevis);
            }

            return $copie;
        });

        try {
            $resultat = (new CalculPrixArticle())->calculer(
                $ajustes,
                $taux,
                $article->prix_force_ht !== null ? (float) $article->prix_force_ht : null,
                (float) $parametres->frais_generaux_fournitures
            );
        } catch (QualificationInconnueException $e) {
            throw ValidationException::withMessages(['article_prix_id' => $e->getMessage()]);
        }

        // TVA : celle de l'article, sauf si la TVA du devis a ete reglee a la main.
        $tva = $parametres->tva_source === 'modifie' ? $parametres->tva_defaut : $article->taux_tva;

        return DB::transaction(function () use ($devis, $article, $quantite, $lotId, $emplacementId, $resultat, $taux, $origines, $ajustes, $tva, $calculees): LigneDevis {
            $ligne = LigneDevis::create([
                'devis_id' => $devis->getKey(),
                'lot_id' => $lotId,
                'emplacement_id' => $emplacementId,
                'article_prix_id' => $article->getKey(),
                'code_article' => $article->reference,
                'libelle' => $article->designation,
                'descriptif' => $article->description,
                'dtu_reference' => $article->dtu_reference,
                'quantite' => $quantite,
                'unite' => $article->unite->value,
                'prix_unitaire_ht' => $resultat->prixVenteHt,
                'prix_revient_unitaire' => $resultat->revientComplet ? $resultat->prixRevientHt : null,
                'prix_force' => $article->prix_force_ht !== null,
                'taux_tva' => $tva,
                'ordre' => ((int) LigneDevis::query()->where('devis_id', $devis->getKey())->max('ordre')) + 1,
            ]);

            foreach ($origines as $rang => $composant) {
                $estMainOeuvre = $composant->type === TypeComposant::MainOeuvre;
                $tauxHoraire = $estMainOeuvre ? $taux[(string) $composant->qualification] : null;

                LigneDevisComposant::create([
                    'ligne_devis_id' => $ligne->getKey(),
                    'type' => $composant->type->value,
                    'designation' => $composant->designation,
                    'quantite' => $ajustes[$rang]->quantite,
                    'quantite_base' => $estMainOeuvre ? $composant->quantite : null,
                    'qualification' => $estMainOeuvre ? (string) $composant->qualification : null,
                    'fourniture_id' => $composant->fourniture_id,
                    'unite' => $composant->unite,
                    'prix_achat_ht' => $estMainOeuvre ? $tauxHoraire->cout_revient_ht : $composant->prix_achat_ht,
                    'prix_vente_ht' => $estMainOeuvre ? $tauxHoraire->taux_vente_ht : $ajustes[$rang]->prix_vente_ht,
                    'prix_vente_calcule' => isset($calculees[(int) $composant->fourniture_id]),
                    'taux_horaire_libelle' => $estMainOeuvre ? $tauxHoraire->libelle : null,
                    'taux_horaire_vente' => $estMainOeuvre ? $tauxHoraire->taux_vente_ht : null,
                    'cout_revient_horaire' => $estMainOeuvre ? $tauxHoraire->cout_revient_ht : null,
                    'ordre' => $rang + 1,
                ]);
            }

            return $ligne->load('composants');
        });
    }

    /**
     * Fournitures de la base dont le prix de vente est calcule (pas saisi),
     * parmi celles que cite le sous-detail (lot F2b).
     *
     * @param \Illuminate\Support\Collection<int, ArticleComposant> $composants
     * @return array<int, true> Indexe par identifiant de fourniture.
     */
    private function fournituresAPrixCalcule(\Illuminate\Support\Collection $composants): array
    {
        $ids = $composants->pluck('fourniture_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $calculees = [];
        foreach (\App\Models\Fourniture::withTrashed()->whereIn('id', $ids)->where('prix_vente_saisi', false)->pluck('id') as $id) {
            $calculees[(int) $id] = true;
        }

        return $calculees;
    }
}
