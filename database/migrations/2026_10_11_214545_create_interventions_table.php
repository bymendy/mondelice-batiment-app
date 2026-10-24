<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des interventions (planning terrain).
 *
 * Une intervention affecte un salarié à un créneau sur un chantier donné.
 * La détection de conflits de planning (deux interventions qui se
 * chevauchent pour un même salarié) est une logique applicative à
 * implémenter en Phase 3, pas une contrainte posée ici au niveau base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('planifiee');
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventions');
    }
};
