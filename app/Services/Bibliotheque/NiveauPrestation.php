<?php

namespace App\Services\Bibliotheque;

use App\Enums\TypeComposant;
use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\Fourniture;
use App\Models\TauxHoraire;
use App\Models\User;
use Closure;

/**
 * Niveau de prestation (tâche 3.6 bis, lot F4, décisions du 26 novembre 2026).
 *
 * Trois niveaux fixes : économique, standard, haut de gamme. Une ligne
 * « fourniture de la base » du sous-détail garde sa fourniture standard et
 * peut recevoir une variante économique et une variante haut de gamme, de la
 * même unité. À un niveau donné, la ligne prend sa variante ; sans variante,
 * elle garde la fourniture standard. La main-d'œuvre ne change pas.
 *
 * Les variantes ne sont pas recopiées dans la ligne : leur désignation et
 * leurs prix sont lus dans la Base de fournitures (lien vivant). Une variante
 * archivée reste lisible, avec son dernier prix.
 *
 * Article à prix forcé : le prix forcé vaut pour le niveau standard. À un
 * autre niveau, prix = prix forcé + écart de prix de vente des fournitures
 * remplacées. Le contrôle « sous le seuil » se fait alors niveau par niveau :
 * une variante vendue avec une marge trop faible est signalée même si le
 * niveau standard respecte le seuil.
 */
final class NiveauPrestation
{
    public const STANDARD = 'standard';

    public const NIVEAUX = ['economique', 'standard', 'haut_de_gamme'];

    /** Colonne de la variante, par niveau (le standard est `fourniture_id`). */
    public const COLONNES = ['economique' => 'fourniture_eco_id', 'haut_de_gamme' => 'fourniture_haut_id'];

    public const LIBELLES = ['economique' => 'Économique', 'standard' => 'Standard', 'haut_de_gamme' => 'Haut de gamme'];

    /**
     * Identifiants des variantes d'une ligne.
     *
     * @param ArticleComposant $composant
     * @return array{fourniture_eco_id: int|null, fourniture_haut_id: int|null}
     */
    public static function variantes(ArticleComposant $composant): array
    {
        return [
            'fourniture_eco_id' => $composant->fourniture_eco_id !== null ? (int) $composant->fourniture_eco_id : null,
            'fourniture_haut_id' => $composant->fourniture_haut_id !== null ? (int) $composant->fourniture_haut_id : null,
        ];
    }

    /**
     * Sous-détail à un niveau : chaque ligne qui a une variante pour ce niveau
     * est remplacée par une copie (non enregistrée) qui porte la fourniture,
     * la désignation et les prix de la variante.
     *
     * @param iterable<ArticleComposant> $composants
     * @param string $niveau Un des NIVEAUX.
     * @return array{composants: array<int, ArticleComposant>, remplacees: array<int, int>} `remplacees` : identifiants des lignes qui ont changé de gamme.
     */
    public static function composantsAuNiveau(iterable $composants, string $niveau): array
    {
        $lignes = [];
        foreach ($composants as $composant) {
            $lignes[] = $composant;
        }

        $colonne = self::COLONNES[$niveau] ?? null;
        $ids = [];
        if ($colonne !== null) {
            foreach ($lignes as $composant) {
                if ($composant->type === TypeComposant::FournitureBase && $composant->{$colonne} !== null) {
                    $ids[] = (int) $composant->{$colonne};
                }
            }
        }
        if ($ids === []) {
            return ['composants' => $lignes, 'remplacees' => []];
        }

        $fournitures = Fourniture::withTrashed()->whereIn('id', array_unique($ids))->get()->keyBy('id');
        $remplacees = [];

        foreach ($lignes as $position => $composant) {
            $variante = $composant->type === TypeComposant::FournitureBase && $composant->{$colonne} !== null
                ? $fournitures->get((int) $composant->{$colonne})
                : null;
            if ($variante === null) {
                continue;
            }

            $copie = clone $composant;
            $copie->fourniture_id = (int) $variante->getKey();
            $copie->designation = $variante->designation;
            $copie->prix_achat_ht = $variante->prix_achat_ht;
            $copie->prix_vente_ht = $variante->prix_vente_ht;
            $lignes[$position] = $copie;
            $remplacees[] = (int) $composant->getKey();
        }

        return ['composants' => $lignes, 'remplacees' => $remplacees];
    }

    /**
     * Prix de l'article aux trois niveaux.
     *
     * @param ArticlePrix $article Article avec son sous-détail.
     * @param array<string, TauxHoraire> $tauxParCode
     * @param bool $voirPrixAchat Ajoute le revient, le taux de marge et l'indicateur « sous le seuil ».
     * @return array<int, array<string, mixed>>
     *
     * @throws \App\Exceptions\QualificationInconnueException
     */
    public static function prixParNiveau(ArticlePrix $article, array $tauxParCode, bool $voirPrixAchat): array
    {
        $calcul = new CalculPrixArticle();
        $frais = PrixVenteFourniture::fraisGenerauxPct();
        $force = $article->prix_force_ht !== null ? (float) $article->prix_force_ht : null;
        $standard = $calcul->calculer($article->composants, $tauxParCode, null, $frais);
        // Comme pour l'article : seul un prix forcé peut passer sous le seuil.
        $controle = $voirPrixAchat && $force !== null && tenant_id() ? ControleSeuilsPrixForce::pourEntrepriseCourante() : null;
        if ($controle !== null && ! $controle->estActif()) {
            $controle = null;
        }

        $niveaux = [];
        foreach (self::NIVEAUX as $niveau) {
            $auNiveau = self::composantsAuNiveau($article->composants, $niveau);
            $resultat = $auNiveau['remplacees'] === [] ? $standard : $calcul->calculer($auNiveau['composants'], $tauxParCode, null, $frais);
            $prix = self::prixAuNiveau($force, $standard->prixVenteSousDetailHt, $resultat->prixVenteSousDetailHt);

            $ligne = [
                'niveau' => $niveau,
                'libelle' => self::LIBELLES[$niveau],
                'prix_vente_ht' => $prix,
                'fournitures_remplacees' => count($auNiveau['remplacees']),
            ];
            if ($voirPrixAchat) {
                $ligne['prix_revient_ht'] = $resultat->prixRevientHt;
                $ligne['revient_complet'] = $resultat->revientComplet;
                $ligne['taux_marge_pct'] = $resultat->revientComplet && $prix > 0
                    ? round(($prix - $resultat->prixRevientHt) / $prix * 100, 2)
                    : null;
                $minimum = $controle?->prixMinimum($auNiveau['composants']);
                $ligne['sous_seuil'] = $minimum !== null && round($prix, 2) + 0.000001 < $minimum;
                $ligne['prix_minimum_ht'] = $minimum;
            }
            $niveaux[] = $ligne;
        }

        return $niveaux;
    }

    /**
     * Prix de vente d'un article à un niveau.
     *
     * @param float|null $prixForce Prix forcé de l'article (niveau standard), ou null.
     * @param float $venteStandard Prix du sous-détail au niveau standard.
     * @param float $venteNiveau Prix du sous-détail au niveau demandé.
     * @return float
     */
    public static function prixAuNiveau(?float $prixForce, float $venteStandard, float $venteNiveau): float
    {
        return $prixForce === null ? $venteNiveau : round($prixForce + ($venteNiveau - $venteStandard), 2);
    }

    /**
     * Variantes d'une ligne « fourniture de la base », pour l'API.
     *
     * @param ArticleComposant $composant
     * @param bool $voirPrixAchat
     * @return array<string, mixed> `fourniture_eco_id`, `fourniture_haut_id`, `variantes`.
     */
    public static function presenterVariantes(ArticleComposant $composant, bool $voirPrixAchat): array
    {
        $ids = self::variantes($composant);
        $choisies = array_filter($ids, static fn (?int $id): bool => $id !== null);
        $fournitures = $choisies === [] ? collect() : Fourniture::withTrashed()->whereIn('id', $choisies)->get()->keyBy('id');
        $quantite = (float) $composant->quantite;
        $coefficient = 1 + PrixVenteFourniture::fraisGenerauxPct() / 100;

        $variantes = [];
        foreach (self::COLONNES as $niveau => $colonne) {
            $fourniture = $ids[$colonne] !== null ? $fournitures->get($ids[$colonne]) : null;
            if ($fourniture === null) {
                $variantes[$niveau] = null;

                continue;
            }

            $detail = [
                'fourniture_id' => (int) $fourniture->getKey(),
                'reference' => $fourniture->reference,
                'designation' => $fourniture->designation,
                'unite' => $fourniture->unite,
                'prix_vente_ht' => (float) $fourniture->prix_vente_ht,
                'total_vente_ht' => Arrondi::ligne($quantite, (float) $fourniture->prix_vente_ht),
                'archivee' => $fourniture->trashed(),
            ];
            if ($voirPrixAchat) {
                $achat = $fourniture->prix_achat_ht !== null ? (float) $fourniture->prix_achat_ht : null;
                $detail['prix_achat_ht'] = $achat;
                $detail['total_revient_ht'] = $achat !== null ? Arrondi::ligne($quantite, $achat * $coefficient) : null;
            }
            $variantes[$niveau] = $detail;
        }

        return $ids + ['variantes' => $variantes];
    }

    /**
     * Règle de validation d'une variante : une autre fourniture que la
     * standard, de la même unité (la quantité du sous-détail vaut pour les
     * trois niveaux).
     *
     * @param mixed $standardId Fourniture standard de la ligne.
     * @return Closure
     */
    public static function regleVariante(mixed $standardId): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) use ($standardId): void {
            if (! is_numeric($valeur) || ! is_numeric($standardId)) {
                return;
            }
            if ((int) $valeur === (int) $standardId) {
                $echec('La variante doit être une autre fourniture que la fourniture standard.');

                return;
            }

            $unites = Fourniture::withTrashed()->whereIn('id', [(int) $valeur, (int) $standardId])->pluck('unite', 'id');
            if ($unites->has((int) $valeur) && $unites->has((int) $standardId) && $unites[(int) $valeur] !== $unites[(int) $standardId]) {
                $echec(sprintf(
                    'La variante doit avoir la même unité que la fourniture standard (%s).',
                    $unites[(int) $standardId]
                ));
            }
        };
    }

    /**
     * Journalise un changement de variantes sur une ligne (rien si rien ne change).
     *
     * @param User|null $auteur
     * @param ArticlePrix $article
     * @param ArticleComposant $composant Ligne après modification.
     * @param array{fourniture_eco_id: int|null, fourniture_haut_id: int|null} $avant
     * @return void
     */
    public static function journaliser(?User $auteur, ArticlePrix $article, ArticleComposant $composant, array $avant): void
    {
        $apres = self::variantes($composant);
        if ($avant === $apres) {
            return;
        }

        activity()->performedOn($article)->causedBy($auteur)->event('variantes_gamme_modifiees')
            ->withProperties(['avant' => $avant, 'apres' => $apres, 'composant_id' => (int) $composant->getKey()])
            ->log(sprintf('Variantes de gamme de la ligne « %s » modifiées dans « %s ».', $composant->designation, $article->reference));
    }

    /**
     * Variantes vides (ligne qui vient d'être créée).
     *
     * @return array{fourniture_eco_id: null, fourniture_haut_id: null}
     */
    public static function aucune(): array
    {
        return ['fourniture_eco_id' => null, 'fourniture_haut_id' => null];
    }
}
