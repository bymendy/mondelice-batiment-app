<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Reglage propre a une entreprise cliente (tache 3.4 bis).
 *
 * Usage :
 *   Parametre::lire('commercial_peut_modifier_bibliotheque');       // true par defaut
 *   Parametre::ecrire('commercial_peut_modifier_bibliotheque', false);
 *
 * Seules les cles declarees dans DEFINITIONS sont acceptees.
 *
 * @property string $cle
 * @property mixed $valeur
 */
class Parametre extends Model
{
    use BelongsToTenant;

    protected $table = 'parametres';

    protected $fillable = ['cle', 'valeur'];

    protected $casts = ['valeur' => 'json'];

    /**
     * Reglages connus : libelle affiche, valeur par defaut, regle de
     * validation Laravel. Pour ajouter un reglage, l'ajouter ici.
     *
     * @var array<string, array{libelle: string, defaut: mixed, regle: string}>
     */
    public const DEFINITIONS = [
        'commercial_peut_modifier_bibliotheque' => [
            'libelle' => 'Le Commercial peut créer et modifier les articles de la Bibliothèque de prix',
            'defaut' => true,
            'regle' => 'boolean',
        ],
    ];

    /**
     * Lit un reglage pour le tenant courant.
     *
     * @param string $cle Cle declaree dans DEFINITIONS.
     * @return mixed Valeur enregistree, ou valeur par defaut.
     *
     * @throws \InvalidArgumentException Si la cle est inconnue.
     * @throws \RuntimeException Si aucun tenant courant n'est identifie
     *                            (evite de lire le reglage d'une autre entreprise).
     */
    public static function lire(string $cle): mixed
    {
        self::verifier($cle);

        $ligne = static::query()->where('cle', $cle)->first();

        return $ligne ? $ligne->valeur : self::DEFINITIONS[$cle]['defaut'];
    }

    /**
     * Enregistre un reglage pour le tenant courant (cree ou remplace).
     *
     * @param string $cle Cle declaree dans DEFINITIONS.
     * @param mixed $valeur Nouvelle valeur (deja validee par l'appelant).
     * @return self
     *
     * @throws \InvalidArgumentException Si la cle est inconnue.
     * @throws \RuntimeException Si aucun tenant courant n'est identifie.
     */
    public static function ecrire(string $cle, mixed $valeur): self
    {
        self::verifier($cle);

        $ligne = static::query()->firstOrNew(['cle' => $cle]);
        $ligne->valeur = $valeur;
        $ligne->save();

        return $ligne;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private static function verifier(string $cle): void
    {
        if (! array_key_exists($cle, self::DEFINITIONS)) {
            throw new \InvalidArgumentException("Reglage inconnu : {$cle}");
        }

        if (! tenant_id()) {
            throw new \RuntimeException('Aucun tenant courant : impossible de lire ou ecrire un reglage.');
        }
    }
}
