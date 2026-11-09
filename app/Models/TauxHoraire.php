<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Taux horaire d'une qualification de l'entreprise (Bibliotheque de prix, lot 2).
 *
 * Le `code` (ex. « ouvrier ») est la cle stable citee par les lignes de
 * main-d'oeuvre des articles. cout_revient_ht (salaire charge) est reserve a la
 * Direction : il sera retire des reponses API des autres roles.
 *
 * tenant_id est gere automatiquement par BelongsToTenant.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $code
 * @property string $libelle
 * @property float $taux_vente_ht
 * @property float|null $cout_revient_ht
 * @property bool $par_defaut
 * @property int $ordre
 */
class TauxHoraire extends Model
{
    use BelongsToTenant;

    protected $table = 'taux_horaires';

    protected $fillable = [
        'code',
        'libelle',
        'taux_vente_ht',
        'cout_revient_ht',
        'par_defaut',
        'ordre',
    ];

    protected $casts = [
        'taux_vente_ht' => 'decimal:2',
        'cout_revient_ht' => 'decimal:2',
        'par_defaut' => 'boolean',
        'ordre' => 'integer',
    ];

    /**
     * Tous les taux de l'entreprise courante, indexes par code.
     *
     * Format attendu par CalculPrixArticle::calculer().
     *
     * @return array<string, TauxHoraire>
     */
    public static function indexesParCode(): array
    {
        return static::query()->orderBy('ordre')->get()->keyBy('code')->all();
    }
}
