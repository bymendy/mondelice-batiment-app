<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Devis\CalculTotauxDevis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un devis, rattache a un client et, le cas echeant, a une affaire.
 *
 * Table `devis` (anciennement `quotes`). Les totaux sont calcules a partir
 * des lignes (voir recalculerTotaux) et gardes dans la fiche ; ils seront
 * figes a l'envoi (lots suivants).
 *
 * Le statut est une simple colonne texte pour l'instant (« en_preparation ») ;
 * la machine a etats (App\States\Devis) y sera branchee au lot D2.
 * Le numero est attribue par un service de numerotation (lot D1) : unique
 * dans l'entreprise, absent tant qu'il n'est pas attribue.
 *
 * Avenants : `devis_parent_id` et `rang_avenant` (logique au lot D5).
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $opportunite_id
 * @property int|null $adresse_chantier_id
 * @property int|null $devis_parent_id
 * @property int|null $rang_avenant
 * @property string|null $numero
 * @property string|null $titre
 * @property string $statut
 * @property string $portee prive | entreprise
 * @property int|null $auteur_id
 * @property \Illuminate\Support\Carbon|null $date_devis
 * @property int $validite_jours
 * @property \Illuminate\Support\Carbon|null $date_expiration
 * @property string $total_ht
 * @property string $total_tva
 * @property string $total_ttc
 * @property \Illuminate\Support\Carbon|null $envoye_le
 * @property \Illuminate\Support\Carbon|null $accepte_le
 * @property string|null $preuve_acceptation
 * @property string|null $notes
 */
class Devis extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'devis';

    /**
     * Les totaux, le statut et les dates d'envoi et d'acceptation ne se
     * remplissent pas en masse : ils sont poses par les services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'opportunite_id',
        'adresse_chantier_id',
        'numero',
        'titre',
        'portee',
        'auteur_id',
        'date_devis',
        'validite_jours',
        'date_expiration',
        'notes',
    ];

    protected $casts = [
        'date_devis' => 'date',
        'date_expiration' => 'date',
        'validite_jours' => 'integer',
        'rang_avenant' => 'integer',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'envoye_le' => 'datetime',
        'accepte_le' => 'datetime',
    ];

    /**
     * Le client destinataire (meme archive).
     *
     * @return BelongsTo<Client, Devis>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * L'affaire dont le devis decoule.
     *
     * @return BelongsTo<Opportunite, Devis>
     */
    public function opportunite(): BelongsTo
    {
        return $this->belongsTo(Opportunite::class, 'opportunite_id');
    }

    /**
     * L'adresse du chantier.
     *
     * @return BelongsTo<Adresse, Devis>
     */
    public function adresseChantier(): BelongsTo
    {
        return $this->belongsTo(Adresse::class, 'adresse_chantier_id')->withTrashed();
    }

    /**
     * L'auteur du devis.
     *
     * @return BelongsTo<User, Devis>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    /**
     * Le devis d'origine d'un avenant.
     *
     * @return BelongsTo<Devis, Devis>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'devis_parent_id');
    }

    /**
     * Les avenants de ce devis.
     *
     * @return HasMany<Devis>
     */
    public function avenants(): HasMany
    {
        return $this->hasMany(self::class, 'devis_parent_id')->orderBy('rang_avenant');
    }

    /**
     * @return HasMany<LotDevis>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(LotDevis::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * @return HasMany<LigneDevis>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDevis::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * Recalcule les totaux depuis les lignes et les enregistre.
     *
     * @return array<string, mixed> Le detail du calcul (voir CalculTotauxDevis).
     */
    public function recalculerTotaux(): array
    {
        $resultat = (new CalculTotauxDevis())->calculer($this->lignes()->get());

        $this->forceFill([
            'total_ht' => $resultat['total_ht'],
            'total_tva' => $resultat['total_tva'],
            'total_ttc' => $resultat['total_ttc'],
        ])->save();

        return $resultat;
    }
}
