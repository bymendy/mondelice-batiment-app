<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des opportunités (leads).
 *
 * Une opportunité précède le devis dans le cheminement commercial :
 * Nouveau -> Qualifié -> Visite planifiée -> Visite faite -> (devis créé).
 * Le statut est stocké en texte libre (pas d'enum strict) pour rester
 * souple pendant que la machine à états complète est formalisée en Phase 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('status')->default('nouveau');
            $table->string('source')->nullable(); // ex: bouche-a-oreille, site web, salon
            $table->decimal('estimated_amount', 10, 2)->nullable();
            $table->date('visit_planned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
