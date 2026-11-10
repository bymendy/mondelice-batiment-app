<?php

namespace App\Services\Bibliotheque;

use App\Models\ArticleComposant;
use App\Models\ArticlePrix;
use App\Models\CategoriePrix;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creation, personnalisation et publication des articles de l'entreprise
 * (Bibliotheque de prix, lot 4).
 *
 *  - creer()         : nouvel article, prive ou d'entreprise, code automatique si absent ;
 *  - personnaliser() : copie d'une reference (ou d'une version de l'entreprise)
 *                      en version a soi, avec son propre code ;
 *  - publier()       : passe une version privee a la portee `entreprise`.
 *
 * Les droits (qui peut faire quoi) sont controles par le controleur ; ce service
 * garantit la coherence des donnees : une seule version d'entreprise par
 * article d'origine, une seule version privee par article d'origine et par
 * proprietaire. Les erreurs de regle metier sont des reponses 422.
 */
final class VersionsArticlePrix
{
    public function __construct(private readonly GenerateurCodeArticle $generateur)
    {
    }

    /**
     * Cree un article de l'entreprise.
     *
     * @param User $utilisateur Auteur (proprietaire d'une version privee).
     * @param array<string, mixed> $donnees Champs valides ; `reference` facultatif (code automatique).
     * @param string $portee `prive` ou `entreprise`.
     * @return ArticlePrix
     */
    public function creer(User $utilisateur, array $donnees, string $portee): ArticlePrix
    {
        return DB::transaction(function () use ($utilisateur, $donnees, $portee): ArticlePrix {
            if (empty($donnees['reference'])) {
                $categorie = ! empty($donnees['categorie_id']) ? CategoriePrix::find($donnees['categorie_id']) : null;
                $donnees['reference'] = $this->generateur->prochain(tenant_id(), $categorie);
            }

            $article = new ArticlePrix($donnees);
            $this->poserPortee($article, $utilisateur, $portee);
            $article->save();

            return $article;
        });
    }

    /**
     * Copie `$source` en version a soi.
     *
     * L'article d'origine est la reference elle-meme, ou celle dont la source
     * est deja une version. Un article cree de toutes pieces n'a pas d'origine :
     * il n'y a rien a personnaliser.
     *
     * @param ArticlePrix $source Reference ou version visible par l'utilisateur.
     * @param User $utilisateur
     * @param string $portee `prive` ou `entreprise`.
     * @return ArticlePrix La nouvelle version.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 422 si l'article est deja le votre ou si la version existe deja.
     */
    public function personnaliser(ArticlePrix $source, User $utilisateur, string $portee): ArticlePrix
    {
        $origineId = $source->estReference() ? $source->id : $source->article_origine_id;

        abort_if(
            $origineId === null || $this->estDejaLaVersionDe($source, $portee),
            422,
            'Cet article est déjà le vôtre : modifiez-le directement.'
        );

        return $this->transactionUnique(function () use ($source, $utilisateur, $portee, $origineId): ArticlePrix {
            abort_if(
                $this->versionExistante($origineId, $utilisateur, $portee) !== null,
                422,
                $portee === 'entreprise'
                    ? 'L\'entreprise a déjà une version personnalisée de cet article.'
                    : 'Vous avez déjà une version personnalisée de cet article.'
            );

            $copie = new ArticlePrix($source->only((new ArticlePrix())->getFillable()));
            $copie->reference = $this->generateur->prochain(
                tenant_id(),
                $source->categorie_id !== null ? CategoriePrix::find($source->categorie_id) : null
            );
            $copie->article_origine_id = $origineId;
            $this->poserPortee($copie, $utilisateur, $portee);
            $copie->save();

            foreach ($source->composants as $composant) {
                $copie->composants()->create(Arr::except(
                    $composant->only((new ArticleComposant())->getFillable()),
                    ['article_id']
                ));
            }

            return $copie->load('composants');
        });
    }

    /**
     * Passe une version privee a la portee `entreprise`.
     *
     * @param ArticlePrix $article Version privee (de l'utilisateur, ou orpheline pour la Direction).
     * @return ArticlePrix
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 422 si l'article n'est pas prive,
     *         ou si l'entreprise a deja une version de cet article d'origine.
     */
    public function publier(ArticlePrix $article): ArticlePrix
    {
        abort_if($article->portee !== 'prive', 422, 'Seule une version privée peut être publiée pour l\'entreprise.');

        return $this->transactionUnique(function () use ($article): ArticlePrix {
            abort_if(
                $article->article_origine_id !== null
                    && $this->versionEntreprise($article->article_origine_id) !== null,
                422,
                'L\'entreprise a déjà une version personnalisée de cet article.'
            );

            $article->forceFill([
                'portee' => 'entreprise',
                'proprietaire_id' => null,
                'publie_le' => now(),
            ])->save();

            return $article;
        });
    }

    /**
     * Transaction qui traduit une violation d'unicite (deux demandes
     * simultanees pour la meme version) en reponse 422.
     *
     * @param callable $action
     * @return ArticlePrix
     */
    private function transactionUnique(callable $action): ArticlePrix
    {
        try {
            return DB::transaction($action);
        } catch (UniqueConstraintViolationException) {
            abort(422, 'Une version personnalisée de cet article existe déjà.');
        }
    }

    /**
     * @param ArticlePrix $article
     * @param User $utilisateur
     * @param string $portee
     * @return void
     */
    private function poserPortee(ArticlePrix $article, User $utilisateur, string $portee): void
    {
        $article->portee = $portee;
        $article->proprietaire_id = $portee === 'prive' ? $utilisateur->getKey() : null;
        $article->publie_le = $portee === 'entreprise' ? now() : null;
    }

    /**
     * La source est-elle deja la version que l'on voulait creer ?
     *
     * @param ArticlePrix $source
     * @param string $portee
     * @return bool
     */
    private function estDejaLaVersionDe(ArticlePrix $source, string $portee): bool
    {
        if ($source->estReference()) {
            return false;
        }

        // Une version privee est deja a soi (a publier, pas a recopier) ; une
        // version d'entreprise est deja celle de l'entreprise.
        return $source->portee === 'prive' || $portee === 'entreprise';
    }

    /**
     * Version deja creee pour cet origine, a la portee voulue.
     *
     * @param int $origineId
     * @param User $utilisateur
     * @param string $portee
     * @return ArticlePrix|null
     */
    private function versionExistante(int $origineId, User $utilisateur, string $portee): ?ArticlePrix
    {
        if ($portee === 'entreprise') {
            return $this->versionEntreprise($origineId);
        }

        return ArticlePrix::query()
            ->where('article_origine_id', $origineId)
            ->where('portee', 'prive')
            ->where('proprietaire_id', $utilisateur->getKey())
            ->first();
    }

    /**
     * @param int $origineId
     * @return ArticlePrix|null
     */
    private function versionEntreprise(int $origineId): ?ArticlePrix
    {
        return ArticlePrix::query()
            ->where('article_origine_id', $origineId)
            ->where('portee', 'entreprise')
            ->first();
    }
}
