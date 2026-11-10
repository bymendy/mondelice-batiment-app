<?php

namespace App\Services\Bibliotheque;

use App\Enums\TypeComposant;
use App\Models\ArticleComposant;
use App\Models\TauxHoraire;
use App\Models\User;

/**
 * Forme JSON d'une ligne du sous-detail d'un article (Bibliotheque de prix, lot 5).
 *
 * Main-d'oeuvre : le prix unitaire vient du taux horaire de la qualification
 * (le prix stocke dans la ligne est NULL). Autres types : prix stockes dans la
 * ligne.
 *
 * Les prix d'achat, couts de revient et totaux de revient ne sont renvoyes
 * qu'aux utilisateurs qui ont `bibliotheque.voir_prix_achat`.
 *
 * Les totaux de ligne sont arrondis a l'affichage ; le prix de l'article est
 * arrondi une seule fois sur la somme (CalculPrixArticle), il peut donc differer
 * de la somme des lignes affichees d'un centime au plus.
 */
final class PresentateurComposant
{
    /**
     * @param array<string, TauxHoraire> $tauxParCode Taux de l'entreprise courante.
     * @param bool $voirPrixAchat L'utilisateur peut voir achats et revients.
     */
    public function __construct(
        private readonly array $tauxParCode,
        private readonly bool $voirPrixAchat,
    ) {
    }

    /**
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
     * @param ArticleComposant $composant
     * @return array<string, mixed>
     */
    public function presenter(ArticleComposant $composant): array
    {
        $quantite = (float) $composant->quantite;
        $estMainOeuvre = $composant->type === TypeComposant::MainOeuvre;

        $donnees = [
            'id' => $composant->id,
            'article_id' => $composant->article_id,
            'type' => $composant->type->value,
            'qualification' => $composant->qualification,
            'designation' => $composant->designation,
            'quantite' => $quantite,
            'unite' => $composant->unite,
            'prix_vente_ht' => $composant->prix_vente_ht !== null ? (float) $composant->prix_vente_ht : null,
            'ordre' => $composant->ordre,
        ];

        if ($this->voirPrixAchat) {
            $donnees['prix_achat_ht'] = $composant->prix_achat_ht !== null ? (float) $composant->prix_achat_ht : null;
        }

        $venteUnitaire = $composant->prix_vente_ht !== null ? (float) $composant->prix_vente_ht : null;
        $achatUnitaire = $composant->prix_achat_ht !== null ? (float) $composant->prix_achat_ht : null;

        if ($estMainOeuvre) {
            $taux = $this->tauxParCode[(string) $composant->qualification] ?? null;

            if ($taux === null) {
                // Jamais de prix devine : on le signale.
                $donnees['erreur_prix'] = "Qualification inconnue : « {$composant->qualification} ».";
                $venteUnitaire = null;
                $achatUnitaire = null;
            } else {
                $venteUnitaire = (float) $taux->taux_vente_ht;
                $achatUnitaire = $taux->cout_revient_ht !== null ? (float) $taux->cout_revient_ht : null;
            }
        }

        $donnees['prix_vente_unitaire_ht'] = $venteUnitaire;
        $donnees['total_vente_ht'] = $venteUnitaire !== null ? round($quantite * $venteUnitaire, 2) : null;

        if ($this->voirPrixAchat) {
            $donnees['prix_achat_unitaire_ht'] = $achatUnitaire;
            $donnees['total_revient_ht'] = $achatUnitaire !== null ? round($quantite * $achatUnitaire, 2) : null;
        }

        return $donnees;
    }
}
