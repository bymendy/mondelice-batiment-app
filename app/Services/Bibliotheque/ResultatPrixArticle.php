<?php

namespace App\Services\Bibliotheque;

/**
 * Resultat du calcul du prix d'un article (jamais stocke en base).
 *
 * Montants en euros arrondis a 2 decimales. Les champs de revient, marge et
 * coefficient valent null quand le prix de revient est incomplet (une ligne
 * sans prix d'achat, ou une qualification sans cout de revient) : on n'affiche
 * jamais une marge fausse.
 *
 * Les champs de revient sont sensibles : l'API les retire pour les roles sans
 * `bibliotheque.voir_prix_achat`.
 */
final readonly class ResultatPrixArticle
{
    /**
     * @param float $prixVenteHt Prix de vente unitaire retenu (prix force s'il existe).
     * @param float $prixVenteSousDetailHt Somme du sous-detail (quantite x prix de vente).
     * @param float|null $prixForceHt Prix impose a la main, ou null.
     * @param float|null $ecartPrixForceHt Prix force - sous-detail, ou null sans prix force.
     * @param float $prixRevientHt Somme des couts connus (quantite x prix d'achat ou cout de revient).
     * @param bool $revientComplet Faux si au moins un cout est inconnu.
     * @param float|null $margeHt Prix de vente - prix de revient, ou null si revient incomplet.
     * @param float|null $coefficient Prix de vente / prix de revient (si revient > 0 et complet).
     * @param float|null $tauxMargePct Marge / prix de vente x 100 (si prix de vente > 0 et revient complet).
     */
    public function __construct(
        public float $prixVenteHt,
        public float $prixVenteSousDetailHt,
        public ?float $prixForceHt,
        public ?float $ecartPrixForceHt,
        public float $prixRevientHt,
        public bool $revientComplet,
        public ?float $margeHt,
        public ?float $coefficient,
        public ?float $tauxMargePct,
    ) {
    }
}
