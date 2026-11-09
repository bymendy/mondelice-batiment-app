<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Clients / CRM, lot 1 : la table `contacts` (fiche client de la
 * tache 3.4) devient `clients`. Le nom `contacts` est libere pour les
 * personnes a joindre (migration suivante).
 *
 * - renomme aussi sequence, index et cle etrangere (PostgreSQL ne le fait
 *   pas) : sinon la creation de la nouvelle table `contacts` entrerait en
 *   conflit de noms ;
 * - ajoute tva_intra et ne_plus_demarcher_le ;
 * - SIRET unique par entreprise (hors lignes archivees) ;
 * - opportunities.contact_id et quotes.contact_id deviennent client_id.
 *
 * Les donnees existantes sont conservees. Un SIRET en double dans une meme
 * entreprise ferait echouer la creation de l'index : a corriger avant.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE contacts RENAME TO clients');
        DB::statement('ALTER SEQUENCE IF EXISTS contacts_id_seq RENAME TO clients_id_seq');
        DB::statement('ALTER INDEX IF EXISTS contacts_pkey RENAME TO clients_pkey');
        DB::statement('ALTER INDEX IF EXISTS contacts_tenant_id_nom_index RENAME TO clients_tenant_id_nom_index');
        DB::statement('ALTER INDEX IF EXISTS contacts_tenant_id_raison_sociale_index RENAME TO clients_tenant_id_raison_sociale_index');
        DB::statement('ALTER TABLE clients RENAME CONSTRAINT contacts_tenant_id_foreign TO clients_tenant_id_foreign');

        Schema::table('clients', function (Blueprint $table): void {
            $table->string('tva_intra', 20)->nullable()->after('siret');
            $table->date('ne_plus_demarcher_le')->nullable()->after('source_lead');
        });

        DB::statement(
            'CREATE UNIQUE INDEX clients_tenant_siret_unique ON clients (tenant_id, siret) '
            . 'WHERE siret IS NOT NULL AND deleted_at IS NULL'
        );

        foreach (['opportunities', 'quotes'] as $nomTable) {
            Schema::table($nomTable, function (Blueprint $table): void {
                $table->dropForeign(['contact_id']);
            });
            Schema::table($nomTable, function (Blueprint $table): void {
                $table->renameColumn('contact_id', 'client_id');
            });
            Schema::table($nomTable, function (Blueprint $table): void {
                $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            });
        }
    }

    /**
     * Migration volontairement irreversible : les noms `contacts` designent
     * desormais d'autres donnees.
     *
     * @return void
     *
     * @throws RuntimeException Toujours.
     */
    public function down(): void
    {
        throw new RuntimeException('Migration irreversible : `contacts` designe desormais les personnes a joindre.');
    }
};
