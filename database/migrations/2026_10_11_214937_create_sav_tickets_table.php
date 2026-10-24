<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des tickets SAV.
 *
 * Module complet avec une entité propre (pas juste un statut de chantier),
 * décision actée section 5.1 du cadrage fonctionnel. Statuts (section 5.3) :
 * Ouvert -> Planifié -> Intervention faite -> Clos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sav_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->string('status')->default('ouvert');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->date('reported_at');
            $table->date('intervention_planned_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sav_tickets');
    }
};
