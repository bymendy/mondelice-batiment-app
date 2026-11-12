<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

/**
 * Modèle Tenant applicatif (étend le modèle de base de stancl/tenancy).
 *
 * Nécessaire pour déclarer preferred_language comme une vraie colonne de
 * table plutôt que comme un champ stocké dans la colonne JSON `data`
 * (comportement par défaut du package pour tout attribut non déclaré).
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Cree les reglages du CRM (etapes du pipeline, sources, motifs) des la
     * creation de l'entreprise.
     *
     * @return void
     */
    protected static function booted(): void
    {
        parent::booted();

        static::created(function (Tenant $entreprise): void {
            \App\Services\Crm\InitialisationCrm::pour((string) $entreprise->getTenantKey());
        });
    }

    /**
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'preferred_language',
        ];
    }
}
