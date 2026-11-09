<?php

namespace Tests\Feature;

use App\Exceptions\TenantNonIdentifieException;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Durcissement du filtre d'entreprise : sans entreprise identifiee, un modele
 * metier refuse la lecture et la creation au lieu de laisser passer sans
 * filtre (cause de la fuite corrigee le 4 novembre 2026).
 */
class FiltreTenantStrictTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        tenancy()->end();
    }

    public function test_la_lecture_sans_entreprise_est_refusee(): void
    {
        $this->expectException(TenantNonIdentifieException::class);

        Client::query()->get();
    }

    public function test_la_creation_sans_entreprise_ni_tenant_id_est_refusee(): void
    {
        $this->expectException(TenantNonIdentifieException::class);

        Client::create(['type' => 'particulier', 'nom' => 'Dupont']);
    }

    public function test_la_creation_avec_tenant_id_explicite_reste_possible(): void
    {
        $entreprise = $this->creerEntreprise();

        $contact = new Client(['type' => 'particulier', 'nom' => 'Dupont']);
        $contact->tenant_id = $entreprise->getTenantKey();
        $contact->save();

        $this->assertDatabaseHas('clients', ['id' => $contact->id, 'tenant_id' => $entreprise->getTenantKey()]);
    }

    public function test_le_contournement_explicite_voit_toutes_les_entreprises(): void
    {
        $a = $this->creerEntreprise();
        $b = $this->creerEntreprise();

        $this->dansEntreprise($a, fn () => Client::create(['type' => 'particulier', 'nom' => 'A']));
        $this->dansEntreprise($b, fn () => Client::create(['type' => 'particulier', 'nom' => 'B']));

        $this->assertSame(2, Client::sansFiltreTenant()->count());
    }

    public function test_user_reste_lisible_sans_entreprise_pour_l_authentification(): void
    {
        $this->assertSame(0, User::query()->count());
    }
}
