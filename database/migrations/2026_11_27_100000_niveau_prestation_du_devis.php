<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Niveau de prestation dans le devis (tâche 3.6 bis, lot F4b).
 *
 *  - `devis_parametres.niveau_prestation` : niveau du devis, copié du réglage
 *    de l'entreprise à la création.
 *  - `lignes_devis.niveau_prestation` : niveau appliqué à la ligne ;
 *    `niveau_prestation_fixe` : vrai quand il a été choisi pour cette ligne
 *    (le recalcul du devis ne le change pas).
 *  - `lignes_devis_composants` : la fourniture standard et les deux variantes
 *    relevées dans l'article au moment de la copie. `fourniture_id` reste la
 *    fourniture réellement utilisée.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('devis_parametres', function (Blueprint $table): void {
            $table->string('niveau_prestation', 20)->default('standard');
        });

        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->string('niveau_prestation', 20)->nullable();
            $table->boolean('niveau_prestation_fixe')->default(false);
        });

        Schema::table('lignes_devis_composants', function (Blueprint $table): void {
            $table->foreignId('fourniture_standard_id')->nullable()->constrained('fournitures')->nullOnDelete();
            $table->foreignId('fourniture_eco_id')->nullable()->constrained('fournitures')->nullOnDelete();
            $table->foreignId('fourniture_haut_id')->nullable()->constrained('fournitures')->nullOnDelete();
        });

        // Les lignes déjà écrites ont été copiées au niveau standard.
        DB::statement('UPDATE lignes_devis_composants SET fourniture_standard_id = fourniture_id WHERE fourniture_id IS NOT NULL');
        DB::statement("UPDATE lignes_devis SET niveau_prestation = 'standard' WHERE article_prix_id IS NOT NULL");
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('lignes_devis_composants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fourniture_standard_id');
            $table->dropConstrainedForeignId('fourniture_eco_id');
            $table->dropConstrainedForeignId('fourniture_haut_id');
        });

        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->dropColumn(['niveau_prestation', 'niveau_prestation_fixe']);
        });

        Schema::table('devis_parametres', function (Blueprint $table): void {
            $table->dropColumn('niveau_prestation');
        });
    }
};
