<?php

namespace App\Models;

use App\Enums\SourceLead;
use App\Enums\TypeContact;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact / Client (tache 3.4) : racine du modele de donnees.
 *
 * tenant_id est gere automatiquement par BelongsToTenant (jamais dans
 * $fillable). Suppression douce : un contact deja reference par un devis
 * ou un chantier ne doit jamais disparaitre physiquement.
 *
 * @property TypeContact $type
 * @property string|null $nom
 * @property string|null $prenom
 * @property string|null $raison_sociale
 * @property string|null $siret
 * @property string|null $adresse
 * @property string|null $code_postal
 * @property string|null $ville
 * @property string|null $telephone
 * @property string|null $email
 * @property SourceLead|null $source_lead
 * @property string|null $notes
 */
class Contact extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'contacts';

    protected $fillable = [
        'type',
        'nom',
        'prenom',
        'raison_sociale',
        'siret',
        'adresse',
        'code_postal',
        'ville',
        'telephone',
        'email',
        'source_lead',
        'notes',
    ];

    protected $casts = [
        'type' => TypeContact::class,
        'source_lead' => SourceLead::class,
    ];

    /**
     * Nom a afficher partout (liste, devis, chantier) : raison sociale
     * pour un professionnel, "NOM Prenom" pour un particulier. Calcule a
     * la volee, jamais stocke.
     *
     * @return string
     */
    public function nomAffiche(): string
    {
        if ($this->type === TypeContact::Professionnel) {
            return (string) $this->raison_sociale;
        }

        return trim(mb_strtoupper((string) $this->nom) . ' ' . $this->prenom);
    }
}
