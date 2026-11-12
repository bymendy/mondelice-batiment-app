<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Clients / CRM, lot O1 : la source d'un client passe de
 * l'enumeration `source_lead` (liste fermee) a `source_id` (liste
 * parametrable par entreprise). Les valeurs existantes sont reprises : les
 * codes des sources par defaut sont ceux de l'ancienne enumeration.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('source_id')->nullable()->after('email')->constrained('sources_leads')->nullOnDelete();
        });

        DB::statement(
            'UPDATE clients c SET source_id = s.id FROM sources_leads s '
            . 'WHERE s.tenant_id = c.tenant_id AND s.code = c.source_lead'
        );

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('source_lead');
        });
    }

    /**
     * Restaure l'enumeration a partir du code de la source.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('source_lead')->nullable();
        });

        DB::statement(
            "UPDATE clients c SET source_lead = s.code FROM sources_leads s "
            . "WHERE s.id = c.source_id AND s.code IN ('bouche_a_oreille', 'site_web', 'reseaux_sociaux', 'ancien_client', 'recommandation', 'autre')"
        );

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_id');
        });
    }
};
