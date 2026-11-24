<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marge des fournitures par devis (tâche 3.6 bis, lot F2b).
 *
 *  - `devis_parametres.marge_fournitures_source` : « entreprise » ou « modifie ».
 *  - `lignes_devis_composants.prix_vente_calcule` : la ligne note si le prix
 *    de vente de sa fourniture était calculé (et suit donc la marge du devis)
 *    ou saisi (et ne bouge pas).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('devis_parametres', function (Blueprint $table): void {
            $table->string('marge_fournitures_source', 20)->default('entreprise');
        });

        Schema::table('lignes_devis_composants', function (Blueprint $table): void {
            $table->boolean('prix_vente_calcule')->default(false);
        });

        // Reprise des lignes écrites depuis le lot F1.
        DB::statement(
            'UPDATE lignes_devis_composants c SET prix_vente_calcule = true FROM fournitures f '
            . 'WHERE f.id = c.fourniture_id AND f.prix_vente_saisi = false'
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('lignes_devis_composants', function (Blueprint $table): void {
            $table->dropColumn('prix_vente_calcule');
        });
        Schema::table('devis_parametres', function (Blueprint $table): void {
            $table->dropColumn('marge_fournitures_source');
        });
    }
};
