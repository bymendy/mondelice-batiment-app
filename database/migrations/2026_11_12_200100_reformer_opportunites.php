<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Clients / CRM, lot O1 : la table `opportunities` (Phase 1) devient
 * `opportunites`, alignee sur le modele du CRM.
 *
 * - renomme aussi sequence, index et cle etrangere ;
 * - tenant_id passe de uuid a texte avec cle etrangere vers tenants (comme
 *   `clients`) ; les lignes sans entreprise la reprennent de leur client ;
 * - `status` devient etape_id (reprise : nouveau, qualifie -> a_qualifier,
 *   visite_planifiee, visite_faite ; tout autre statut -> nouveau) ;
 * - `source` (texte libre) est recopiee dans les notes puis supprimee ;
 * - estimated_amount -> montant_estime, visit_planned_at -> visite_prevue_le ;
 * - nouvelles colonnes : contact, adresse de chantier, titre, metiers,
 *   probabilite, date de decision, source, commercial, devis principal,
 *   motif de perte, concurrent, prochaine action, dates de gain / perte,
 *   suppression douce.
 *
 * Les donnees existantes sont conservees. quotes.opportunity_id suit le
 * renommage (la table `quotes` sera reformee avec le module Devis).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE opportunities RENAME TO opportunites');
        DB::statement('ALTER SEQUENCE IF EXISTS opportunities_id_seq RENAME TO opportunites_id_seq');
        DB::statement('ALTER INDEX IF EXISTS opportunities_pkey RENAME TO opportunites_pkey');
        DB::statement('ALTER INDEX IF EXISTS opportunities_tenant_id_index RENAME TO opportunites_tenant_id_index');
        DB::statement('ALTER TABLE opportunites RENAME CONSTRAINT opportunities_client_id_foreign TO opportunites_client_id_foreign');

        DB::statement('ALTER TABLE opportunites ALTER COLUMN tenant_id TYPE varchar(255) USING tenant_id::text');
        DB::statement(
            'UPDATE opportunites o SET tenant_id = c.tenant_id FROM clients c WHERE c.id = o.client_id AND o.tenant_id IS NULL'
        );

        Schema::table('opportunites', function (Blueprint $table): void {
            $table->renameColumn('estimated_amount', 'montant_estime');
            $table->renameColumn('visit_planned_at', 'visite_prevue_le');
        });

        Schema::table('opportunites', function (Blueprint $table): void {
            $table->foreignId('contact_id')->nullable()->after('client_id')->constrained('contacts')->nullOnDelete();
            $table->foreignId('adresse_chantier_id')->nullable()->after('contact_id')->constrained('adresses')->nullOnDelete();
            $table->string('titre')->nullable()->after('adresse_chantier_id');
            $table->jsonb('metiers')->nullable();
            $table->foreignId('etape_id')->nullable()->constrained('etapes_pipeline')->restrictOnDelete();
            $table->unsignedSmallInteger('probabilite')->nullable();
            $table->date('date_decision_prevue')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('sources_leads')->nullOnDelete();
            $table->foreignId('commercial_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('devis_principal_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('motif_perte_id')->nullable()->constrained('motifs')->nullOnDelete();
            $table->string('concurrent')->nullable();
            $table->string('prochaine_action')->nullable();
            $table->date('prochaine_action_le')->nullable();
            $table->timestamp('gagnee_le')->nullable();
            $table->timestamp('perdue_le')->nullable();
            $table->softDeletes();
        });

        DB::statement(
            "UPDATE opportunites o SET etape_id = e.id, probabilite = e.probabilite_defaut "
            . "FROM etapes_pipeline e WHERE e.tenant_id = o.tenant_id AND e.code = "
            . "CASE o.status WHEN 'qualifie' THEN 'a_qualifier' WHEN 'visite_planifiee' THEN 'visite_planifiee' "
            . "WHEN 'visite_faite' THEN 'visite_faite' ELSE 'nouveau' END"
        );
        DB::statement("UPDATE opportunites SET titre = 'Affaire ' || id");
        DB::statement(
            "UPDATE opportunites SET notes = trim(both E'\\n' from coalesce(notes, '') || E'\\nSource (ancienne saisie) : ' || source) "
            . "WHERE source IS NOT NULL AND source <> ''"
        );

        Schema::table('opportunites', function (Blueprint $table): void {
            $table->dropColumn(['status', 'source']);
        });

        DB::statement('ALTER TABLE opportunites ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement('ALTER TABLE opportunites ALTER COLUMN titre SET NOT NULL');
        DB::statement('ALTER TABLE opportunites ALTER COLUMN etape_id SET NOT NULL');
        DB::statement('ALTER TABLE opportunites ALTER COLUMN probabilite SET NOT NULL');
        DB::statement('ALTER TABLE opportunites ADD CONSTRAINT opportunites_probabilite_check CHECK (probabilite BETWEEN 0 AND 100)');

        Schema::table('opportunites', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'etape_id']);
            $table->index(['tenant_id', 'commercial_id']);
            $table->index(['tenant_id', 'prochaine_action_le']);
        });
    }

    /**
     * Migration volontairement irreversible : des colonnes et des donnees
     * (etape, source) n'ont pas d'equivalent dans l'ancienne table.
     *
     * @return void
     *
     * @throws RuntimeException Toujours.
     */
    public function down(): void
    {
        throw new RuntimeException('Migration irreversible : `opportunites` remplace `opportunities`.');
    }
};
