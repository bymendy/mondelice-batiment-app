<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la langue préférée de l'utilisateur.
 *
 * Surcharge individuelle de la langue par défaut du tenant (voir migration
 * add_preferred_language_to_tenants_table). Architecture i18n-ready actée
 * en section 5.11 du cadrage fonctionnel : français uniquement en V1, mais
 * aucun texte codé en dur côté frontend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_language', 5)->default('fr')->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferred_language');
        });
    }
};
