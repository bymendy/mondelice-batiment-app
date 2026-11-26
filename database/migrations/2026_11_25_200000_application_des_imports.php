<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application des imports de catalogues (tâche 3.6 bis, lot F3b).
 *
 *  - `fournitures.date_tarif` : date du tarif du fournisseur (information).
 *  - `imports_fournitures` : qui a appliqué l'import, quand, et son bilan.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('fournitures', function (Blueprint $table): void {
            $table->date('date_tarif')->nullable();
        });

        Schema::table('imports_fournitures', function (Blueprint $table): void {
            $table->timestamp('applique_le')->nullable();
            $table->foreignId('applique_par')->nullable()->constrained('users')->nullOnDelete();
            $table->json('bilan')->nullable();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('imports_fournitures', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('applique_par');
            $table->dropColumn(['applique_le', 'bilan']);
        });

        Schema::table('fournitures', function (Blueprint $table): void {
            $table->dropColumn('date_tarif');
        });
    }
};
