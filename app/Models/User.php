<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Modèle représentant un utilisateur de l'application.
 *
 * Chaque utilisateur appartient à un tenant (entreprise cliente) via la
 * colonne tenant_id, gérée automatiquement par le trait BelongsToTenant :
 * voir app/Models/Concerns/BelongsToTenant.php.
 *
 * HasRoles (Spatie Permission) ajouté tâche 2.12 : permet
 * $user->assignRole()/hasRole(). Rôles globaux (teams => false, voir
 * RoleSeeder) --- aucun risque de récursion avec tenant_id() comme pour la
 * résolution de l'utilisateur authentifié (voir Mondelice_Phase2_Backend.tex,
 * section 4), car HasRoles n'interroge jamais Auth::user() ni tenancy().
 *
 * Champs MFA (tâche 2.15) : two_factor_secret et two_factor_recovery_codes
 * sont chiffrés en base (cast "encrypted"/"encrypted:array", clé APP_KEY) et
 * jamais exposés en JSON (voir #[Hidden] ci-dessous). two_factor_confirmed_at
 * est la source de vérité unique du statut "MFA actif" : NULL = pas encore
 * confirmé par un premier code valide, renseignée = actif. Pas de booléen
 * séparé, pour éviter toute divergence entre deux champs qui devraient
 * toujours être synchronisés.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, BelongsToTenant, HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Le MFA est actif pour cet utilisateur dès qu'il a confirmé
     * l'activation avec un premier code TOTP valide (tâche 2.15).
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Le rôle de cet utilisateur impose-t-il le MFA (décision sécurité,
     * CONTEXTE_SECURITE_RGPD_IA.md §1.2 point 1) ? Direction, Administratif
     * et Ressources Humaines : obligatoire. Les autres rôles : recommandé
     * ou non nécessaire, jamais imposé par le système.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->hasAnyRole(['Direction', 'Administratif', 'Ressources Humaines']);
    }

    /**
     * Exempte du refus par defaut du trait BelongsToTenant : ce modele doit
     * pouvoir etre lu avant l'identification de l'entreprise (l'utilisateur est charge pour s'authentifier, donc avant que son entreprise soit connue).
     *
     * @return bool
     */
    public static function filtreTenantStrict(): bool
    {
        return false;
    }
}
