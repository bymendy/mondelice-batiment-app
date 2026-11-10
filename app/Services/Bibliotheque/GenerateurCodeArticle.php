<?php

namespace App\Services\Bibliotheque;

use App\Models\CategoriePrix;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Code automatique d'un article de l'entreprise (Bibliotheque de prix, lot 4).
 *
 * Forme : `<code de la categorie>-<numero sur 4 chiffres>`, par exemple
 * `1.4.5-0003`. Un article sans categorie prend le prefixe `SC`
 * (« sans categorie »). La numerotation est propre a chaque entreprise et a
 * chaque prefixe : elle repart de 0001 dans une autre entreprise.
 *
 * Le numero suivant est le plus grand numero deja employe + 1, en comptant les
 * articles archives (leur code reste pris par l'unicite en base). Deux
 * creations simultanees ne peuvent pas obtenir le meme numero : un verrou
 * consultatif PostgreSQL, lie a la transaction, serialise les demandes d'une
 * meme entreprise et d'un meme prefixe. Le code doit donc etre demande DANS la
 * transaction qui cree l'article.
 */
final class GenerateurCodeArticle
{
    public const PREFIXE_SANS_CATEGORIE = 'SC';

    /**
     * Prochain code libre.
     *
     * @param string|null $tenantId Entreprise de l'article.
     * @param CategoriePrix|null $categorie Categorie de l'article, ou null.
     * @return string Code, par exemple `1.4-0001`.
     *
     * @throws LogicException Appele hors transaction (le verrou ne protegerait rien).
     */
    public function prochain(?string $tenantId, ?CategoriePrix $categorie): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Le code d\'un article doit etre genere dans une transaction.');
        }

        $prefixe = $categorie !== null && $categorie->code !== '' ? $categorie->code : self::PREFIXE_SANS_CATEGORIE;

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [($tenantId ?? '') . '|' . $prefixe]);

        $requete = DB::table('articles_prix')->where('reference', 'like', $this->echapperLike($prefixe) . '-%');
        $tenantId === null ? $requete->whereNull('tenant_id') : $requete->where('tenant_id', $tenantId);

        $dernier = 0;
        foreach ($requete->pluck('reference') as $reference) {
            if (preg_match('/^' . preg_quote($prefixe, '/') . '-(\d+)$/', (string) $reference, $trouve) === 1) {
                $dernier = max($dernier, (int) $trouve[1]);
            }
        }

        return sprintf('%s-%04d', $prefixe, $dernier + 1);
    }

    /**
     * Neutralise les jokers de LIKE (% et _) ; le code de categorie n'en
     * contient pas, mais le prefixe vient de la base.
     *
     * @param string $texte
     * @return string
     */
    private function echapperLike(string $texte): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texte);
    }
}
