<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frais généraux sur fournitures (tâche 3.6 bis, lot F2a) : le taux de
 * l'entreprise est copié dans les paramètres de chaque devis. Les devis
 * existants gardent 0 : leurs lignes déjà écrites ne changent pas.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('devis_parametres', function (Blueprint $table): void {
            $table->decimal('frais_generaux_fournitures', 5, 2)->default(0);
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('devis_parametres', function (Blueprint $table): void {
            $table->dropColumn('frais_generaux_fournitures');
        });
    }
};
