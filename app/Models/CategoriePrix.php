<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Categorie de la Bibliotheque de prix (arborescence, lot 1).
 *
 * Trois niveaux en V1 : Domaine (1) > Corps d'etat (2) > Famille d'ouvrages (3).
 * Les categories de reference (tenant_id NULL, 8 / 48 / 249) sont partagees par
 * toutes les entreprises ; une entreprise peut ajouter les siennes.
 *
 * Le code est le numero hierarchique (ex. « 1.4.5 ») : position de la categorie
 * parmi ses freres, a partir de 1, precedee du code du parent.
 *
 * tenant_id est gere automatiquement par BelongsToTenant : jamais dans
 * $fillable. Les references se creent par CategoriePrix::creerReference().
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int|null $parent_id
 * @property int $niveau
 * @property int $ordre
 * @property string $code
 * @property string $nom
 * @property-read CategoriePrix|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CategoriePrix> $enfants
 */
class CategoriePrix extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'categories_prix';

    protected $fillable = [
        'parent_id',
        'niveau',
        'ordre',
        'code',
        'nom',
    ];

    protected $casts = [
        'niveau' => 'integer',
        'ordre' => 'integer',
    ];

    /**
     * Les categories de reference (tenant_id NULL) sont visibles par toutes
     * les entreprises.
     *
     * @return bool
     */
    public static function inclutReferences(): bool
    {
        return true;
    }

    /**
     * Categorie parente (NULL pour un domaine, niveau 1).
     *
     * @return BelongsTo<CategoriePrix, CategoriePrix>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Sous-categories directes, dans l'ordre d'affichage.
     *
     * @return HasMany<CategoriePrix>
     */
    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('ordre');
    }

    /**
     * Limite la requete aux domaines (niveau 1, sans parent).
     *
     * @param Builder<CategoriePrix> $requete
     * @return Builder<CategoriePrix>
     */
    public function scopeRacines(Builder $requete): Builder
    {
        return $requete->whereNull('parent_id');
    }
}
