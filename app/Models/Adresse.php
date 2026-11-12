<?php

namespace App\Models;

use App\Enums\TypeAdresse;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Adresse d'un client : une adresse de facturation et autant d'adresses de
 * chantier que necessaire (reutilisees plus tard par les devis et les
 * chantiers). Suppression douce : un devis ou un chantier peut la referencer.
 *
 * tenant_id est gere automatiquement par BelongsToTenant.
 *
 * @property int $id
 * @property int $client_id
 * @property TypeAdresse $type
 * @property string|null $libelle
 * @property string|null $ligne1
 * @property string|null $ligne2
 * @property string|null $code_postal
 * @property string|null $ville
 * @property string $pays
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $infos_acces
 */
class Adresse extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'adresses';

    protected $fillable = [
        'type',
        'libelle',
        'ligne1',
        'ligne2',
        'code_postal',
        'ville',
        'pays',
        'latitude',
        'longitude',
        'infos_acces',
    ];

    protected $casts = [
        'type' => TypeAdresse::class,
    ];

    /**
     * Valeurs par defaut a la creation.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'pays' => 'France',
    ];

    /**
     * Le client proprietaire de l'adresse (meme archive).
     *
     * @return BelongsTo<Client, Adresse>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
