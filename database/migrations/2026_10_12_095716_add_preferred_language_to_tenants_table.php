<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la langue préférée par défaut du tenant (entreprise cliente).
 *
 * Valeur héritée par défaut pour chaque nouvel utilisateur du tenant, sauf
 * surcharge individuelle (voir users.preferred_language). Architecture
 * i18n-ready actée en section 5.11 du cadrage fonctionnel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('preferred_language', 5)->default('fr');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('preferred_language');
        });
    }
};
