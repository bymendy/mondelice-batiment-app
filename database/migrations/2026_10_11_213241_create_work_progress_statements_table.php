<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des situations de travaux.
 *
 * Permet la facturation par avancement, courante en BTP (section 5.3 du
 * cadrage fonctionnel : "Situation à émettre" réintégrée car indispensable).
 * Chaque situation représente un pourcentage d'avancement facturé sur un
 * chantier donné, à une date donnée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_progress_statements', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->unsignedInteger('statement_number');
            $table->decimal('progress_percentage', 5, 2); // ex: 30.00 = 30% d'avancement
            $table->decimal('amount_ht', 10, 2);
            $table->date('statement_date');
            $table->string('status')->default('a_emettre');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_progress_statements');
    }
};
