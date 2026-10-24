<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des lignes de devis.
 *
 * Chaque ligne référence (optionnellement) un item de la bibliothèque de
 * prix, mais copie désignation/prix/TVA au moment de la création : le prix
 * signé reste figé même si le catalogue évolue ensuite (exception légale
 * actée dans le cadrage fonctionnel, section 5.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('price_library_item_id')->nullable()->constrained('price_library_items')->nullOnDelete();
            $table->string('designation');
            $table->string('unit')->default('unite');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price_ht', 10, 2);
            $table->decimal('vat_rate', 5, 2)->default(20.00);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
    }
};
