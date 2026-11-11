<?php

namespace App\Services\Bibliotheque;

use App\Enums\TypeComposant;
use App\Enums\TypeSeuil;
use App\Models\ArticleComposant;
use App\Models\SeuilTarifaire;
use App\Models\TauxHoraire;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Controle d'un prix force a la main contre les seuils de la Direction
 * (Parametres du compte, § 4.3 point 2 bis ; Bibliotheque de prix, lot 6).
 *
 * Le prix minimum d'un article est calcule depuis son sous-detail :
 *
 *   minimum = revient de la main-d'oeuvre / (1 - marge MO minimum)
 *           + revient des autres lignes     / (1 - marge fournitures minimum)
 *
 * La marge est un pourcentage du prix de vente (comme le taux de marge deja
 * affiche). Un type sans seuil actif vaut 0 % : on ne vend pas sous le revient.
 * Le minimum est arrondi au centime superieur : le prix exactement egal au
 * minimum affiche est accepte.
 *
 * Le controle n'existe que si au moins un seuil est actif. Quand il existe et
 * que le revient est inconnu (aucune ligne, prix d'achat ou cout de revient
 * manquant, qualification inconnue), le prix force est refuse : on ne peut pas
 * verifier la marge.
 *
 * Ce service ne decide pas QUI est controle : l'appelant exempte la Direction.
 */
final class ControleSeuilsPrixForce
{
    /**
     * @param array<string, float> $seuils Seuils actifs par type (voir SeuilTarifaire::actifsParType()).
     * @param array<string, TauxHoraire> $tauxParCode Taux de l'entreprise courante.
     */
    public function __construct(
        private readonly array $seuils,
        private readonly array $tauxParCode,
    ) {
    }

    /**
     * Construit le controle pour l'entreprise courante.
     *
     * @return self
     */
    public static function pourEntrepriseCourante(): self
    {
        return new self(SeuilTarifaire::actifsParType(), TauxHoraire::indexesParCode());
    }

    /**
     * Au moins un seuil est-il actif ?
     *
     * @return bool
     */
    public function estActif(): bool
    {
        return $this->seuils !== [];
    }

    /**
     * Refuse (422) un prix force sous le minimum ou non verifiable.
     *
     * @param iterable<ArticleComposant> $composants Sous-detail de l'article (vide a la creation).
     * @param float $prixForceHt Prix force demande.
     * @return void
     *
     * @throws HttpResponseException Reponse 422 : `motif` vaut `sous_le_seuil` ou `revient_inconnu`.
     */
    public function verifier(iterable $composants, float $prixForceHt): void
    {
        if (! $this->estActif()) {
            return;
        }

        $minimum = $this->prixMinimum($composants);
        $seuils = [
            TypeSeuil::MargeMainOeuvre->value => $this->seuils[TypeSeuil::MargeMainOeuvre->value] ?? null,
            TypeSeuil::MargeFournitures->value => $this->seuils[TypeSeuil::MargeFournitures->value] ?? null,
        ];

        if ($minimum === null) {
            $this->refuser(
                'revient_inconnu',
                'Le prix de revient de cet article est incomplet : impossible de vérifier la marge minimum fixée par la Direction. '
                . 'Complétez le sous-détail et les prix d\'achat, ou demandez à la Direction.',
                $prixForceHt,
                null,
                $seuils
            );
        }

        if (round($prixForceHt, 2) + 0.000001 < $minimum) {
            $this->refuser(
                'sous_le_seuil',
                'Vous dépassez le seuil fixé par la Direction : le prix minimum de cet article est de '
                . number_format($minimum, 2, ',', ' ') . ' € HT.',
                $prixForceHt,
                $minimum,
                $seuils
            );
        }
    }

    /**
     * Prix minimum HT, arrondi au centime superieur, ou null si le revient est inconnu.
     *
     * @param iterable<ArticleComposant> $composants
     * @return float|null
     */
    public function prixMinimum(iterable $composants): ?float
    {
        $revientMainOeuvre = 0.0;
        $revientAutres = 0.0;
        $nombre = 0;

        foreach ($composants as $composant) {
            $nombre++;
            $quantite = (float) $composant->quantite;

            if ($composant->type === TypeComposant::MainOeuvre) {
                $taux = $this->tauxParCode[(string) $composant->qualification] ?? null;

                if ($taux === null || $taux->cout_revient_ht === null) {
                    return null;
                }

                $revientMainOeuvre += $quantite * (float) $taux->cout_revient_ht;

                continue;
            }

            if ($composant->prix_achat_ht === null) {
                return null;
            }

            $revientAutres += $quantite * (float) $composant->prix_achat_ht;
        }

        if ($nombre === 0) {
            return null;
        }

        $margeMo = $this->seuils[TypeSeuil::MargeMainOeuvre->value] ?? 0.0;
        $margeAutres = $this->seuils[TypeSeuil::MargeFournitures->value] ?? 0.0;

        $minimum = $revientMainOeuvre / (1 - $margeMo / 100) + $revientAutres / (1 - $margeAutres / 100);

        // round() avant ceil() : absorbe le bruit des flottants (60,0000000001 -> 60,00).
        return ceil(round($minimum * 100, 6)) / 100;
    }

    /**
     * @param string $motif
     * @param string $message
     * @param float $prixForceHt
     * @param float|null $minimum
     * @param array<string, float|null> $seuils
     * @return never
     */
    private function refuser(string $motif, string $message, float $prixForceHt, ?float $minimum, array $seuils): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => ['prix_force_ht' => [$message]],
            'motif' => $motif,
            'prix_force_ht' => round($prixForceHt, 2),
            'prix_minimum_ht' => $minimum,
            'seuils' => $seuils,
        ], 422));
    }
}
