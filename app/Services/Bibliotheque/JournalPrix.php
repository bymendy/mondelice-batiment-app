<?php

namespace App\Services\Bibliotheque;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\SeuilTarifaire;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Ecriture du journal des changements de prix (Bibliotheque de prix, lot 7).
 *
 * Chaque changement de prix, de portee, de sous-detail ou de seuil ajoute une
 * entree au journal d'actions (spatie/laravel-activitylog, table `activity_log`,
 * ecriture seule). L'entree porte :
 *  - le sujet (l'article, ou le seuil) et l'auteur ;
 *  - `event` : le type de changement (`prix_force_modifie`, `composant_ajoute`...) ;
 *  - `properties` : `avant` et `apres` (seulement les champs qui changent) et
 *    un `contexte` facultatif ;
 *  - l'entreprise (`tenant_id`, pose par le modele Activity) et l'origine
 *    (`triggered_by` vaut `human` par defaut).
 *
 * Les prix d'achat sont ecrits en clair ; ils ne sont masques qu'a la lecture,
 * pour qui n'a pas `bibliotheque.voir_prix_achat` (PresentateurHistorique).
 *
 * Le service ne decide rien : les controleurs l'appellent apres les controles
 * de droits et de seuils, dans la meme transaction que l'ecriture.
 */
final class JournalPrix
{
    /** Champs d'un article suivis par `article_modifie` et `prix_force_*`. */
    public const CHAMPS_ARTICLE = [
        'reference', 'designation', 'description', 'type_article', 'categorie_id', 'unite',
        'taux_tva', 'fournisseur', 'actif', 'prix_force_ht', 'dtu_reference', 'dtu_lien',
    ];

    /** Champs d'une ligne du sous-detail. */
    public const CHAMPS_COMPOSANT = [
        'type', 'qualification', 'designation', 'quantite', 'unite',
        'prix_achat_ht', 'prix_vente_ht', 'ordre',
    ];

    /** Champs d'un seuil. */
    public const CHAMPS_SEUIL = ['valeur', 'actif'];

    /** Champs lisibles seulement avec `bibliotheque.voir_prix_achat`. */
    public const CHAMPS_SENSIBLES = ['prix_achat_ht'];

    /**
     * Valeurs normalisees (enums en texte, montants en nombres) de certains
     * champs d'un modele, pour comparer avant et apres.
     *
     * @param Model $modele
     * @param array<int, string> $cles
     * @return array<string, mixed>
     */
    public function instantane(Model $modele, array $cles): array
    {
        $valeurs = [];

        foreach ($cles as $cle) {
            $valeurs[$cle] = $this->normaliser($modele, $cle, $modele->getAttribute($cle));
        }

        return $valeurs;
    }

    /**
     * Un article vient d'etre cree.
     *
     * @param User $auteur
     * @param ArticlePrix $article
     * @return void
     */
    public function articleCree(User $auteur, ArticlePrix $article): void
    {
        $this->ecrire(
            $auteur,
            $article,
            'article_cree',
            "Article « {$article->reference} » créé.",
            [],
            $this->instantane($article, ['reference', 'designation', 'type_article', 'unite', 'portee', 'prix_force_ht'])
        );
    }

    /**
     * Un article vient d'etre modifie. `$avant` est l'instantane pris avant
     * la modification (voir CHAMPS_ARTICLE). Le prix force a sa propre entree
     * (`prix_force_pose`, `prix_force_modifie` ou `prix_force_retire`) ; les
     * autres champs sont regroupes dans `article_modifie`. Rien n'est ecrit
     * pour un champ qui ne change pas.
     *
     * @param User $auteur
     * @param ArticlePrix $article Article apres modification.
     * @param array<string, mixed> $avant
     * @return void
     */
    public function articleModifie(User $auteur, ArticlePrix $article, array $avant): void
    {
        $apres = $this->instantane($article, array_keys($avant));

        [$avantPrix, $apresPrix] = $this->differences(Arr::only($avant, ['prix_force_ht']), Arr::only($apres, ['prix_force_ht']));

        if ($apresPrix !== []) {
            $ancien = $avantPrix['prix_force_ht'];
            $nouveau = $apresPrix['prix_force_ht'];

            if ($ancien === null) {
                [$evenement, $description] = ['prix_force_pose', "Prix forcé posé à {$this->montant($nouveau)} sur « {$article->reference} »."];
            } elseif ($nouveau === null) {
                [$evenement, $description] = ['prix_force_retire', "Prix forcé retiré sur « {$article->reference} » (il valait {$this->montant($ancien)})."];
            } else {
                [$evenement, $description] = ['prix_force_modifie', "Prix forcé de « {$article->reference} » passé de {$this->montant($ancien)} à {$this->montant($nouveau)}."];
            }

            $this->ecrire($auteur, $article, $evenement, $description, $avantPrix, $apresPrix);
        }

        [$avantAutres, $apresAutres] = $this->differences(Arr::except($avant, ['prix_force_ht']), Arr::except($apres, ['prix_force_ht']));

        if ($apresAutres !== []) {
            $champs = implode(', ', array_keys($apresAutres));
            $this->ecrire($auteur, $article, 'article_modifie', "Article « {$article->reference} » modifié ({$champs}).", $avantAutres, $apresAutres);
        }
    }

    /**
     * Une reference (ou une version de l'entreprise) vient d'etre copiee en version a soi.
     *
     * @param User $auteur
     * @param ArticlePrix $copie La nouvelle version, avec son sous-detail charge.
     * @param ArticlePrix $source L'article copie.
     * @return void
     */
    public function articlePersonnalise(User $auteur, ArticlePrix $copie, ArticlePrix $source): void
    {
        $this->ecrire(
            $auteur,
            $copie,
            'article_personnalise',
            "Article « {$source->reference} » personnalisé en « {$copie->reference} » (portée {$copie->portee}).",
            [],
            $this->instantane($copie, ['reference', 'designation', 'portee']),
            [
                'source_id' => $source->id,
                'source_reference' => $source->reference,
                'article_origine_id' => $copie->article_origine_id,
                'composants_copies' => $copie->composants->count(),
            ]
        );
    }

    /**
     * Une version privee vient d'etre publiee pour l'entreprise. `$avant` est
     * l'instantane (`portee`, `proprietaire_id`) pris avant la publication.
     *
     * @param User $auteur
     * @param ArticlePrix $article
     * @param array<string, mixed> $avant
     * @return void
     */
    public function articlePublie(User $auteur, ArticlePrix $article, array $avant): void
    {
        $this->ecrire(
            $auteur,
            $article,
            'article_publie',
            "Article « {$article->reference} » publié pour toute l'entreprise.",
            $avant,
            $this->instantane($article, array_keys($avant))
        );
    }

    /**
     * Une ligne vient d'etre ajoutee au sous-detail.
     *
     * @param User $auteur
     * @param ArticlePrix $article
     * @param ArticleComposant $composant
     * @return void
     */
    public function composantAjoute(User $auteur, ArticlePrix $article, ArticleComposant $composant): void
    {
        $this->ecrire(
            $auteur,
            $article,
            'composant_ajoute',
            "Ligne « {$composant->designation} » ajoutée au sous-détail de « {$article->reference} ».",
            [],
            $this->instantane($composant, self::CHAMPS_COMPOSANT),
            ['composant_id' => $composant->id]
        );
    }

    /**
     * Une ligne vient d'etre modifiee. `$avant` est l'instantane pris avant
     * (voir CHAMPS_COMPOSANT). Rien n'est ecrit si rien ne change.
     *
     * @param User $auteur
     * @param ArticlePrix $article
     * @param ArticleComposant $composant Ligne apres modification.
     * @param array<string, mixed> $avant
     * @return void
     */
    public function composantModifie(User $auteur, ArticlePrix $article, ArticleComposant $composant, array $avant): void
    {
        [$avantChamps, $apresChamps] = $this->differences($avant, $this->instantane($composant, array_keys($avant)));

        if ($apresChamps === []) {
            return;
        }

        $this->ecrire(
            $auteur,
            $article,
            'composant_modifie',
            "Ligne « {$composant->designation} » modifiée dans le sous-détail de « {$article->reference} » (" . implode(', ', array_keys($apresChamps)) . ').',
            $avantChamps,
            $apresChamps,
            ['composant_id' => $composant->id]
        );
    }

    /**
     * Une ligne vient d'etre supprimee. `$avant` est son instantane complet.
     *
     * @param User $auteur
     * @param ArticlePrix $article
     * @param int $composantId
     * @param array<string, mixed> $avant
     * @return void
     */
    public function composantSupprime(User $auteur, ArticlePrix $article, int $composantId, array $avant): void
    {
        $this->ecrire(
            $auteur,
            $article,
            'composant_supprime',
            "Ligne « {$avant['designation']} » supprimée du sous-détail de « {$article->reference} ».",
            $avant,
            [],
            ['composant_id' => $composantId]
        );
    }

    /**
     * Le sous-detail vient d'etre reordonne. Rien n'est ecrit si l'ordre est le meme.
     *
     * @param User $auteur
     * @param ArticlePrix $article
     * @param array<int, int> $avantIds Identifiants des lignes, dans l'ancien ordre.
     * @param array<int, int> $apresIds Identifiants des lignes, dans le nouvel ordre.
     * @return void
     */
    public function composantsReordonnes(User $auteur, ArticlePrix $article, array $avantIds, array $apresIds): void
    {
        if ($avantIds === $apresIds) {
            return;
        }

        $this->ecrire(
            $auteur,
            $article,
            'composants_reordonnes',
            "Sous-détail de « {$article->reference} » réordonné.",
            ['ordre_des_lignes' => $avantIds],
            ['ordre_des_lignes' => $apresIds]
        );
    }

    /**
     * Un seuil vient d'etre fixe (`$avant` nul) ou modifie. Rien n'est ecrit
     * si la valeur et l'etat ne changent pas.
     *
     * @param User $auteur
     * @param SeuilTarifaire $seuil Seuil apres enregistrement.
     * @param array<string, mixed>|null $avant Instantane avant (CHAMPS_SEUIL), ou null s'il n'existait pas.
     * @return void
     */
    public function seuilModifie(User $auteur, SeuilTarifaire $seuil, ?array $avant): void
    {
        $apres = $this->instantane($seuil, self::CHAMPS_SEUIL);
        $libelle = $seuil->type->label();

        if ($avant === null) {
            $this->ecrire(
                $auteur,
                $seuil,
                'seuil_fixe',
                "Seuil « {$libelle} » fixé à {$this->nombre($apres['valeur'])} %.",
                [],
                $apres,
                ['type' => $seuil->type->value]
            );

            return;
        }

        [$avantChamps, $apresChamps] = $this->differences($avant, $apres);

        if ($apresChamps === []) {
            return;
        }

        $this->ecrire(
            $auteur,
            $seuil,
            'seuil_modifie',
            "Seuil « {$libelle} » modifié ({$this->nombre($avant['valeur'])} % vers {$this->nombre($apres['valeur'])} %, " . ($apres['actif'] ? 'actif' : 'inactif') . ').',
            $avantChamps,
            $apresChamps,
            ['type' => $seuil->type->value]
        );
    }

    /**
     * Ajoute l'entree au journal.
     *
     * @param User $auteur
     * @param Model $sujet
     * @param string $evenement
     * @param string $description
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     * @param array<string, mixed> $contexte
     * @return void
     */
    private function ecrire(User $auteur, Model $sujet, string $evenement, string $description, array $avant, array $apres, array $contexte = []): void
    {
        $proprietes = $contexte;

        if ($avant !== []) {
            $proprietes['avant'] = $avant;
        }

        if ($apres !== []) {
            $proprietes['apres'] = $apres;
        }

        activity()
            ->performedOn($sujet)
            ->causedBy($auteur)
            ->event($evenement)
            ->withProperties($proprietes)
            ->log($description);
    }

    /**
     * Champs dont la valeur change entre deux instantanes.
     *
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} Anciennes et nouvelles valeurs des seuls champs modifies.
     */
    private function differences(array $avant, array $apres): array
    {
        $anciennes = [];
        $nouvelles = [];

        foreach ($apres as $cle => $valeur) {
            $ancienne = $avant[$cle] ?? null;

            if (! $this->egaux($ancienne, $valeur)) {
                $anciennes[$cle] = $ancienne;
                $nouvelles[$cle] = $valeur;
            }
        }

        return [$anciennes, $nouvelles];
    }

    /**
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private function egaux(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b) && ! is_string($a) && ! is_string($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return $a === $b;
    }

    /**
     * Valeur stockable dans le journal : enum en texte, date en ISO 8601,
     * montant (cast decimal) en nombre.
     *
     * @param Model $modele
     * @param string $cle
     * @param mixed $valeur
     * @return mixed
     */
    private function normaliser(Model $modele, string $cle, mixed $valeur): mixed
    {
        if ($valeur instanceof BackedEnum) {
            return $valeur->value;
        }

        if ($valeur instanceof DateTimeInterface) {
            return $valeur->format(DATE_ATOM);
        }

        $cast = $modele->getCasts()[$cle] ?? null;

        if ($valeur !== null && is_string($cast) && str_starts_with($cast, 'decimal')) {
            return (float) $valeur;
        }

        return $valeur;
    }

    /**
     * @param mixed $valeur
     * @return string « 12,50 € ».
     */
    private function montant(mixed $valeur): string
    {
        return number_format((float) $valeur, 2, ',', ' ') . ' €';
    }

    /**
     * @param mixed $valeur
     * @return string « 20 » ou « 12,5 ».
     */
    private function nombre(mixed $valeur): string
    {
        return rtrim(rtrim(number_format((float) $valeur, 2, ',', ''), '0'), ',');
    }
}
