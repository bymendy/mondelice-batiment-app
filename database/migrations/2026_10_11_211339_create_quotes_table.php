<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des devis.
 *
 * Un devis est rattaché à un client et, le plus souvent, à l'opportunité
 * dont il découle (nullable : un devis peut aussi être créé directement).
 * Les montants totaux sont stockés ici (calculés et figés à partir des
 * lignes de quote_lines), pour éviter de les recalculer à chaque affichage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->string('quote_number')->nullable();
            $table->string('status')->default('devis_en_preparation');
            $table->date('issued_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->decimal('total_ht', 10, 2)->default(0);
            $table->decimal('total_vat', 10, 2)->default(0);
            $table->decimal('total_ttc', 10, 2)->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
