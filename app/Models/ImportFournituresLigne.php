<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne du comparatif d'un import de fournitures (lot F3a).
 *
 * Actions : `creer` (nouvelle fourniture), `modifier` (fourniture existante
 * qui change), `inchange`, `ignorer` (référence absente de la base en mode
 * « prix »), `erreur` (ligne refusée, voir `erreurs`).
 *
 * `a_valider` signale un écart de prix au-delà du seuil : la ligne n'est pas
 * `retenue` d'office. `anciennes` garde les valeurs actuelles de la
 * fourniture, pour l'affichage du comparatif.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $import_id
 * @property int $numero_ligne Numéro de la ligne dans le fichier.
 * @property string $action
 * @property int|null $fourniture_id
 * @property bool $a_valider
 * @property bool $retenue
 */
class ImportFournituresLigne extends Model
{
    use BelongsToTenant;

    protected $table = 'imports_fournitures_lignes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_id',
        'numero_ligne',
        'action',
        'fourniture_id',
        'reference',
        'designation',
        'unite',
        'prix_tarif_ht',
        'prix_achat_ht',
        'remise_pct',
        'prix_vente_ht',
        'anciennes',
        'modifications',
        'ecart_pct',
        'a_valider',
        'retenue',
        'erreurs',
    ];

    protected $casts = [
        'numero_ligne' => 'integer',
        'prix_tarif_ht' => 'float',
        'prix_achat_ht' => 'float',
        'remise_pct' => 'float',
        'prix_vente_ht' => 'float',
        'anciennes' => 'array',
        'modifications' => 'array',
        'ecart_pct' => 'float',
        'a_valider' => 'boolean',
        'retenue' => 'boolean',
        'erreurs' => 'array',
    ];
}
