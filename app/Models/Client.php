<?php

namespace App\Models;

use App\Enums\SourceLead;
use App\Enums\TypeClient;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Client / prospect (fiche du module Clients / CRM) : racine du modele de
 * donnees. Les personnes a joindre sont des Contact, rattaches par la table
 * pivot client_contact.
 *
 * tenant_id est gere automatiquement par BelongsToTenant (jamais dans
 * $fillable). Suppression douce : un client deja reference par un devis ou
 * un chantier ne doit jamais disparaitre physiquement.
 *
 * Le statut prospect / client / ancien n'est pas stocke : il se deduira de
 * l'historique des devis (module Devis). Seule l'opposition a la
 * prospection est enregistree (ne_plus_demarcher_le).
 *
 * @property TypeClient $type
 * @property string|null $nom
 * @property string|null $prenom
 * @property string|null $raison_sociale
 * @property string|null $siret
 * @property string|null $tva_intra
 * @property string|null $adresse
 * @property string|null $code_postal
 * @property string|null $ville
 * @property string|null $telephone
 * @property string|null $email
 * @property SourceLead|null $source_lead
 * @property \Illuminate\Support\Carbon|null $ne_plus_demarcher_le
 * @property string|null $notes
 */
class Client extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'clients';

    protected $fillable = [
        'type',
        'nom',
        'prenom',
        'raison_sociale',
        'siret',
        'tva_intra',
        'adresse',
        'code_postal',
        'ville',
        'telephone',
        'email',
        'source_lead',
        'ne_plus_demarcher_le',
        'notes',
    ];

    protected $casts = [
        'type' => TypeClient::class,
        'source_lead' => SourceLead::class,
        'ne_plus_demarcher_le' => 'date',
    ];

    /**
     * Nom a afficher partout (liste, devis, chantier) : "NOM Prenom" pour
     * un particulier, raison sociale pour les autres types. Calcule a la
     * volee, jamais stocke.
     *
     * @return string
     */
    public function nomAffiche(): string
    {
        if ($this->type === TypeClient::Particulier) {
            return trim(mb_strtoupper((string) $this->nom) . ' ' . $this->prenom);
        }

        return (string) $this->raison_sociale;
    }

    /**
     * Les personnes a joindre pour ce client (role et indicateur principal
     * dans le pivot).
     *
     * @return BelongsToMany<Contact, Client>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'client_contact')
            ->withPivot(['role', 'principal']);
    }

    /**
     * Rattache (ou met a jour) un contact. Un seul contact principal par
     * client : en designer un nouveau retire le statut a l'ancien.
     *
     * @param Contact $contact Contact a rattacher.
     * @param string|null $role Role chez le client (gardien, comptable...).
     * @param bool $principal Vrai pour en faire le contact principal.
     * @return void
     *
     * @throws LogicException Si le contact appartient a une autre entreprise.
     */
    public function rattacherContact(Contact $contact, ?string $role = null, bool $principal = false): void
    {
        if ((string) $contact->tenant_id !== (string) $this->tenant_id) {
            throw new LogicException('Un contact ne peut etre rattache qu\'a un client de la meme entreprise.');
        }

        DB::transaction(function () use ($contact, $role, $principal): void {
            if ($principal) {
                DB::table('client_contact')
                    ->where('client_id', $this->getKey())
                    ->update(['principal' => false]);
            }

            DB::table('client_contact')->updateOrInsert(
                ['client_id' => $this->getKey(), 'contact_id' => $contact->getKey()],
                ['tenant_id' => $this->tenant_id, 'role' => $role, 'principal' => $principal]
            );
        });
    }

    /**
     * Retire le rattachement d'un contact (le contact lui-meme est conserve).
     *
     * @param Contact $contact Contact a detacher.
     * @return void
     */
    public function detacherContact(Contact $contact): void
    {
        DB::table('client_contact')
            ->where('client_id', $this->getKey())
            ->where('contact_id', $contact->getKey())
            ->delete();
    }

    /**
     * Indique si la prospection commerciale est bloquee : vrai si la fiche
     * ou, le cas echeant, le contact vise a exerce son opposition.
     *
     * @param Contact|null $contact Contact vise par l'envoi, s'il y en a un.
     * @return bool
     */
    public function prospectionBloquee(?Contact $contact = null): bool
    {
        return $this->ne_plus_demarcher_le !== null
            || ($contact !== null && $contact->ne_plus_demarcher_le !== null);
    }
}
