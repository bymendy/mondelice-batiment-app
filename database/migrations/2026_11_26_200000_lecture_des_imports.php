<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lecture des imports de catalogues (tâche 3.6 bis, lot F3c) : feuille Excel
 * lue, ligne d'en-tête, début du fichier (pour choisir cette ligne) et
 * avertissements de l'analyse.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('imports_fournitures', function (Blueprint $table): void {
            $table->json('feuilles')->nullable();
            $table->unsignedSmallInteger('feuille')->default(0);
            $table->unsignedInteger('ligne_entete')->default(1);
            $table->json('debut_fichier')->nullable();
            $table->json('avertissements')->nullable();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('imports_fournitures', function (Blueprint $table): void {
            $table->dropColumn(['feuilles', 'feuille', 'ligne_entete', 'debut_fichier', 'avertissements']);
        });
    }
};
