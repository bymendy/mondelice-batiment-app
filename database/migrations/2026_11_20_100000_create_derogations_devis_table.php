<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot D4c : dérogations aux seuils du devis. Une demande porte sur un devis et
 * un seuil ; la Direction l'accepte, avec une valeur accordée, ou la refuse.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('derogations_devis', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->string('type_seuil', 40);
            $table->string('statut', 20)->default('en_attente');
            $table->decimal('seuil', 5, 2);
            $table->decimal('valeur_demandee', 6, 2);
            $table->decimal('valeur_accordee', 6, 2)->nullable();
            $table->text('motif');
            $table->text('motif_decision')->nullable();
            $table->foreignId('demandeur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decideur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('demandee_le');
            $table->timestamp('decidee_le')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'devis_id', 'type_seuil', 'statut']);
        });

        DB::statement("ALTER TABLE derogations_devis ADD CONSTRAINT derogations_devis_statut_check CHECK (statut IN ('en_attente','acceptee','refusee','annulee'))");
        DB::statement("ALTER TABLE derogations_devis ADD CONSTRAINT derogations_devis_type_check CHECK (type_seuil IN ('marge_globale_min','remise_max'))");
        // Une seule demande en attente par devis et par seuil.
        DB::statement("CREATE UNIQUE INDEX derogations_devis_une_en_attente ON derogations_devis (devis_id, type_seuil) WHERE statut = 'en_attente'");
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('derogations_devis');
    }
};
