<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table de la bibliothèque de prix.
 *
 * Catalogue réutilisable de prestations/fournitures avec leur prix et leur
 * taux de TVA, pour éviter la double saisie à chaque ligne de devis
 * (principe anti-duplication acté). Les lignes de devis (table
 * `quote_lines`, à venir) référencent un item d'ici, mais copient le prix
 * au moment de la signature du devis (exception légale : le prix signé
 * reste figé même si le catalogue évolue ensuite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_library_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('designation');
            $table->string('unit')->default('unite'); // ex: m2, heure, forfait, unite
            $table->decimal('unit_price_ht', 10, 2);
            $table->decimal('vat_rate', 5, 2)->default(20.00);
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_library_items');
    }
};
