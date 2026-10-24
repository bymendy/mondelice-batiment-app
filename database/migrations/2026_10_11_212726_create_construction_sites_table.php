<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des chantiers.
 *
 * Un chantier est créé à partir d'un devis accepté (principe directeur :
 * une affaire = un dossier unique relié à tous les modules). Le statut
 * suit la famille "Chantier" du cadrage fonctionnel (section 5.3) :
 * Créé -> À planifier -> Planifié -> Confirmé -> Prêt à démarrer ->
 * En cours -> ... -> Terminé -> Réceptionné -> Soldé -> SAV -> Clos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_sites', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->string('status')->default('cree');
            $table->text('address')->nullable();
            $table->date('planned_start_at')->nullable();
            $table->date('planned_end_at')->nullable();
            $table->date('actual_start_at')->nullable();
            $table->date('actual_end_at')->nullable();
            $table->timestamp('received_at')->nullable(); // réception légale des travaux
            $table->timestamp('settled_at')->nullable();   // soldé
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_sites');
    }
};
