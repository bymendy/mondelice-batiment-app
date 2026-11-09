<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact : une personne a joindre, rattachee a un ou plusieurs clients
 * par la table pivot client_contact (gardien d'un syndic, comptable d'une
 * entreprise, conjoint d'un particulier...).
 *
 * Le droit d'opposition a la prospection est personnel : il est porte par
 * le contact (ne_plus_demarcher_le), independamment de la fiche client.
 *
 * @property string|null $civilite
 * @property string|null $prenom
 * @property string $nom
 * @property string|null $email
 * @property string|null $telephone
 * @property string|null $mobile
 * @property string|null $preference_contact
 * @property \Illuminate\Support\Carbon|null $ne_plus_demarcher_le
 */
class Contact extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'contacts';

    protected $fillable = [
        'civilite',
        'prenom',
        'nom',
        'email',
        'telephone',
        'mobile',
        'preference_contact',
        'ne_plus_demarcher_le',
    ];

    protected $casts = [
        'ne_plus_demarcher_le' => 'date',
    ];

    /**
     * Nom a afficher : "Prenom NOM".
     *
     * @return string
     */
    public function nomAffiche(): string
    {
        return trim((string) $this->prenom . ' ' . mb_strtoupper((string) $this->nom));
    }

    /**
     * Les clients auxquels ce contact est rattache.
     *
     * @return BelongsToMany<Client, Contact>
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_contact')
            ->withPivot(['role', 'principal']);
    }
}
