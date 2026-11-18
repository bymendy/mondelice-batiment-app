<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remises du devis (module Devis, lot D4a).
 *
 *  - lignes_devis.remise_pct : remise en pourcentage sur une ligne ;
 *  - deductions_devis : remises par lot (lot_id renseigne) ou globales
 *    (lot_id vide), en pourcentage ou en montant, avec un libelle visible du
 *    client.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->decimal('remise_pct', 5, 2)->default(0);
        });
        DB::statement(
            'ALTER TABLE lignes_devis ADD CONSTRAINT lignes_devis_remise_pct_check CHECK (remise_pct BETWEEN 0 AND 100)'
        );

        Schema::create('deductions_devis', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('lots_devis')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('type', 20);
            $table->decimal('valeur', 12, 2);
            $table->unsignedInteger('ordre')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['devis_id', 'ordre']);
            $table->index('tenant_id');
        });

        DB::statement(
            "ALTER TABLE deductions_devis ADD CONSTRAINT deductions_devis_type_check CHECK (type IN ('pourcentage', 'montant'))"
        );
        DB::statement(
            'ALTER TABLE deductions_devis ADD CONSTRAINT deductions_devis_valeur_check CHECK (valeur > 0)'
        );
        DB::statement(
            "ALTER TABLE deductions_devis ADD CONSTRAINT deductions_devis_pourcentage_check "
            . "CHECK (type <> 'pourcentage' OR valeur <= 100)"
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('deductions_devis');
        DB::statement('ALTER TABLE lignes_devis DROP CONSTRAINT IF EXISTS lignes_devis_remise_pct_check');
        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->dropColumn('remise_pct');
        });
    }
};
