<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une affaire (opportunite) du pipeline commercial, rattachee a un client.
 *
 * Elle precede le devis ; une fois le devis cree, l'etape suit le statut du
 * devis (module Devis). `etape_id` est l'etat courant ; chaque changement
 * d'etape est conserve dans `opportunite_etapes` (insertion seule).
 *
 * A la creation, sans etape precisee, l'affaire entre a l'etape « Nouveau »
 * de l'entreprise avec la probabilite par defaut de l'etape.
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $contact_id
 * @property int|null $adresse_chantier_id
 * @property string $titre
 * @property array<int, string>|null $metiers
 * @property string|null $montant_estime
 * @property int $etape_id
 * @property int $probabilite
 * @property \Illuminate\Support\Carbon|null $date_decision_prevue
 * @property \Illuminate\Support\Carbon|null $visite_prevue_le
 * @property int|null $source_id
 * @property int|null $commercial_id
 * @property int|null $devis_principal_id
 * @property int|null $motif_perte_id
 * @property string|null $concurrent
 * @property string|null $prochaine_action
 * @property \Illuminate\Support\Carbon|null $prochaine_action_le
 * @property \Illuminate\Support\Carbon|null $gagnee_le
 * @property \Illuminate\Support\Carbon|null $perdue_le
 * @property string|null $notes
 */
class Opportunite extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'opportunites';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'contact_id',
        'adresse_chantier_id',
        'titre',
        'metiers',
        'montant_estime',
        'etape_id',
        'probabilite',
        'date_decision_prevue',
        'visite_prevue_le',
        'source_id',
        'commercial_id',
        'motif_perte_id',
        'concurrent',
        'prochaine_action',
        'prochaine_action_le',
        'notes',
    ];

    protected $casts = [
        'metiers' => 'array',
        'montant_estime' => 'decimal:2',
        'probabilite' => 'integer',
        'date_decision_prevue' => 'date',
        'visite_prevue_le' => 'date',
        'prochaine_action_le' => 'date',
        'gagnee_le' => 'datetime',
        'perdue_le' => 'datetime',
    ];

    /**
     * Etape « Nouveau » et probabilite par defaut a la creation.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (Opportunite $affaire): void {
            if ($affaire->etape_id === null) {
                $affaire->etape_id = EtapePipeline::query()->where('code', 'nouveau')->value('id');
            }

            if ($affaire->probabilite === null && $affaire->etape_id !== null) {
                $affaire->probabilite = EtapePipeline::query()->whereKey($affaire->etape_id)->value('probabilite_defaut');
            }
        });
    }

    /**
     * Le client a l'origine de cette affaire (meme archive).
     *
     * @return BelongsTo<Client, Opportunite>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * La personne a joindre pour cette affaire.
     *
     * @return BelongsTo<Contact, Opportunite>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * L'adresse du chantier.
     *
     * @return BelongsTo<Adresse, Opportunite>
     */
    public function adresseChantier(): BelongsTo
    {
        return $this->belongsTo(Adresse::class, 'adresse_chantier_id')->withTrashed();
    }

    /**
     * @return BelongsTo<EtapePipeline, Opportunite>
     */
    public function etape(): BelongsTo
    {
        return $this->belongsTo(EtapePipeline::class, 'etape_id');
    }

    /**
     * @return BelongsTo<Source, Opportunite>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    /**
     * Le commercial responsable de l'affaire.
     *
     * @return BelongsTo<User, Opportunite>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commercial_id');
    }

    /**
     * @return BelongsTo<Motif, Opportunite>
     */
    public function motifPerte(): BelongsTo
    {
        return $this->belongsTo(Motif::class, 'motif_perte_id');
    }

    /**
     * Les devis rattaches a l'affaire.
     *
     * @return HasMany<Quote>
     */
    public function devis(): HasMany
    {
        return $this->hasMany(Quote::class, 'opportunity_id');
    }

    /**
     * Historique des changements d'etape (insertion seule).
     *
     * @return HasMany<OpportuniteEtape>
     */
    public function historique(): HasMany
    {
        return $this->hasMany(OpportuniteEtape::class)->orderBy('change_le')->orderBy('id');
    }
}
