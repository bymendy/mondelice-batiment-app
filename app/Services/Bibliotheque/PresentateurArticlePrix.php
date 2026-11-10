<?php

namespace App\Services\Bibliotheque;

use App\Exceptions\QualificationInconnueException;
use App\Models\ArticlePrix;
use App\Models\TauxHoraire;
use App\Models\User;

/**
 * Forme JSON d'un article de la Bibliotheque de prix pour l'API.
 *
 * Le prix de vente est calcule a la volee (CalculPrixArticle). Le prix de
 * revient, la marge, le coefficient et le taux de marge ne sont renvoyes qu'aux
 * utilisateurs qui ont la permission `bibliotheque.voir_prix_achat`.
 *
 * Les taux horaires sont lus une seule fois pour toute une liste.
 */
final class PresentateurArticlePrix
{
    /**
     * @param array<string, TauxHoraire> $tauxParCode Taux de l'entreprise courante.
     * @param bool $voirPrixAchat L'utilisateur peut voir revient et marges.
     */
    public function __construct(
        private readonly array $tauxParCode,
        private readonly bool $voirPrixAchat,
    ) {
    }

    /**
     * Construit le presentateur pour l'utilisateur et l'entreprise courants.
     *
     * @param User|null $utilisateur
     * @return self
     */
    public static function pour(?User $utilisateur): self
    {
        return new self(
            TauxHoraire::indexesParCode(),
            $utilisateur !== null && $utilisateur->can('bibliotheque.voir_prix_achat')
        );
    }

    /**
     * @param ArticlePrix $article Article, de preference avec `composants` charge.
     * @return array<string, mixed>
     */
    public function presenter(ArticlePrix $article): array
    {
        $donnees = [
            'id' => $article->id,
            'reference' => $article->reference,
            'designation' => $article->designation,
            'description' => $article->description,
            'type_article' => $article->type_article->value,
            'categorie_id' => $article->categorie_id,
            'unite' => $article->unite->value,
            'taux_tva' => (float) $article->taux_tva,
            'fournisseur' => $article->fournisseur,
            'actif' => $article->actif,
            'prix_force_ht' => $article->prix_force_ht !== null ? (float) $article->prix_force_ht : null,
            'dtu_reference' => $article->dtu_reference,
            'dtu_lien' => $article->dtu_lien,
            'est_reference' => $article->estReference(),
            'portee' => $article->portee,
            'source' => $article->source,
            'version' => $article->version,
            'article_origine_id' => $article->article_origine_id,
            'prix_mis_a_jour_le' => $article->prix_mis_a_jour_le?->toIso8601String(),
            'created_at' => $article->created_at?->toIso8601String(),
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];

        try {
            $resultat = $article->calculerPrix($this->tauxParCode);
        } catch (QualificationInconnueException $e) {
            // Jamais de prix devine : on le signale.
            $donnees['prix_vente_ht'] = null;
            $donnees['prix_vente_ttc'] = null;
            $donnees['erreur_prix'] = $e->getMessage();

            return $donnees;
        }

        $donnees['prix_vente_ht'] = $resultat->prixVenteHt;
        $donnees['prix_vente_ttc'] = round($resultat->prixVenteHt * (1 + ((float) $article->taux_tva / 100)), 2);
        $donnees['prix_vente_sous_detail_ht'] = $resultat->prixVenteSousDetailHt;
        $donnees['ecart_prix_force_ht'] = $resultat->ecartPrixForceHt;

        if ($this->voirPrixAchat) {
            $donnees['prix_revient_ht'] = $resultat->prixRevientHt;
            $donnees['revient_complet'] = $resultat->revientComplet;
            $donnees['marge_ht'] = $resultat->margeHt;
            $donnees['coefficient'] = $resultat->coefficient;
            $donnees['taux_marge_pct'] = $resultat->tauxMargePct;
        }

        return $donnees;
    }
}
