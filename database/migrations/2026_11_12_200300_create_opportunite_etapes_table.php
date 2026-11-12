<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Clients / CRM, lot O1 : historique des changements d'etape d'une
 * affaire. INSERTION SEULE : un declencheur refuse toute modification et
 * toute suppression directe (les actions de cle etrangere, comme la
 * suppression d'une entreprise, restent possibles). Sert a compter chaque affaire une fois par etape.
 *
 * triggered_by (10 caracteres max) et automation_rule (20 max) reprennent
 * le vocabulaire et les tailles du journal d'activite.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('opportunite_etapes', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('opportunite_id')->constrained('opportunites')->cascadeOnDelete();
            $table->foreignId('etape_depuis_id')->nullable()->constrained('etapes_pipeline')->restrictOnDelete();
            $table->foreignId('etape_vers_id')->constrained('etapes_pipeline')->restrictOnDelete();
            $table->timestamp('change_le')->useCurrent();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('triggered_by', 10)->default('human');
            $table->string('automation_rule', 20)->nullable();
            $table->foreignId('devis_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->boolean('retour_arriere')->default(false);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'etape_vers_id']);
            $table->index(['opportunite_id', 'change_le']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION opportunite_etapes_insertion_seule() RETURNS trigger AS $$
BEGIN
    -- Une action de cle etrangere (suppression en cascade d'une entreprise
    -- ou d'une affaire, mise a NULL de l'auteur ou du devis) s'execute dans
    -- un declencheur interne : profondeur > 1, elle est autorisee.
    IF pg_trigger_depth() > 1 THEN
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'opportunite_etapes est en insertion seule (% refuse)', TG_OP;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::unprepared(
            'CREATE TRIGGER opportunite_etapes_insertion_seule BEFORE UPDATE OR DELETE ON opportunite_etapes '
            . 'FOR EACH ROW EXECUTE FUNCTION opportunite_etapes_insertion_seule()'
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunite_etapes');
        DB::unprepared('DROP FUNCTION IF EXISTS opportunite_etapes_insertion_seule()');
    }
};
