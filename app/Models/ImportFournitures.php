<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un fichier de catalogue envoyé pour un fournisseur (lot F3a).
 *
 * Statuts : `envoye` (fichier lu, colonnes à confirmer), `analyse`
 * (comparatif prêt), `applique` (lot F3b), `annule`.
 * Modes : `prix` (met à jour les prix, ne crée rien) ou `complet` (crée et
 * met à jour).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $fournisseur_id
 * @property int|null $user_id
 * @property string $nom_fichier
 * @property string $chemin Chemin du fichier sur le disque privé.
 * @property string $format csv | xlsx
 * @property string $mode prix | complet
 * @property string $statut
 * @property int $nb_lignes Lignes de données (sans l'en-tête).
 * @property array<int, string> $entetes
 * @property array<int, array<int, string>> $apercu
 * @property array<string, int|null> $correspondance_proposee
 * @property array<string, int|null>|null $correspondance
 * @property array<string, int>|null $resume
 */
class ImportFournitures extends Model
{
    use BelongsToTenant;

    protected $table = 'imports_fournitures';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fournisseur_id',
        'user_id',
        'nom_fichier',
        'chemin',
        'format',
        'mode',
        'statut',
        'date_tarif',
        'nb_lignes',
        'entetes',
        'apercu',
        'correspondance_proposee',
        'correspondance',
        'unite_defaut',
        'seuil_ecart_pct',
        'resume',
    ];

    protected $casts = [
        'date_tarif' => 'date:Y-m-d',
        'nb_lignes' => 'integer',
        'entetes' => 'array',
        'apercu' => 'array',
        'correspondance_proposee' => 'array',
        'correspondance' => 'array',
        'seuil_ecart_pct' => 'decimal:2',
        'resume' => 'array',
    ];

    /**
     * @return BelongsTo<Fournisseur, $this>
     */
    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    /**
     * @return HasMany<ImportFournituresLigne, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(ImportFournituresLigne::class, 'import_id');
    }
}
