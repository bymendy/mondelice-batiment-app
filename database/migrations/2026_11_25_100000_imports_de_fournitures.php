<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Import des catalogues des fournisseurs (tâche 3.6 bis, lot F3a).
 *
 *  - `imports_fournitures` : un fichier envoyé pour un fournisseur, avec sa
 *    correspondance de colonnes et le résumé de son analyse.
 *  - `imports_fournitures_lignes` : le comparatif, ligne par ligne. Rien
 *    n'est écrit dans la Base de fournitures tant que l'import n'est pas
 *    appliqué (lot F3b).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('imports_fournitures', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nom_fichier');
            $table->string('chemin');
            $table->string('format', 10);
            $table->string('mode', 10);
            $table->string('statut', 20)->default('envoye');
            $table->date('date_tarif')->nullable();
            $table->unsignedInteger('nb_lignes')->default(0);
            $table->json('entetes');
            $table->json('apercu');
            $table->json('correspondance_proposee');
            $table->json('correspondance')->nullable();
            $table->string('unite_defaut', 20)->nullable();
            $table->decimal('seuil_ecart_pct', 7, 2)->nullable();
            $table->json('resume')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'fournisseur_id']);
        });

        DB::statement("ALTER TABLE imports_fournitures ADD CONSTRAINT imports_fournitures_mode_check CHECK (mode IN ('prix', 'complet'))");
        DB::statement("ALTER TABLE imports_fournitures ADD CONSTRAINT imports_fournitures_statut_check CHECK (statut IN ('envoye', 'analyse', 'applique', 'annule'))");

        Schema::create('imports_fournitures_lignes', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('import_id')->constrained('imports_fournitures')->cascadeOnDelete();
            $table->unsignedInteger('numero_ligne');
            $table->string('action', 20);
            $table->foreignId('fourniture_id')->nullable()->constrained('fournitures')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->string('designation', 500)->nullable();
            $table->string('unite', 50)->nullable();
            $table->decimal('prix_tarif_ht', 14, 4)->nullable();
            $table->decimal('prix_achat_ht', 14, 4)->nullable();
            $table->decimal('remise_pct', 7, 2)->nullable();
            $table->decimal('prix_vente_ht', 14, 4)->nullable();
            $table->json('anciennes')->nullable();
            $table->json('modifications')->nullable();
            $table->decimal('ecart_pct', 12, 2)->nullable();
            $table->boolean('a_valider')->default(false);
            $table->boolean('retenue')->default(false);
            $table->json('erreurs')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['import_id', 'action']);
            $table->index(['import_id', 'numero_ligne']);
        });

        DB::statement("ALTER TABLE imports_fournitures_lignes ADD CONSTRAINT imports_fournitures_lignes_action_check CHECK (action IN ('creer', 'modifier', 'inchange', 'ignorer', 'erreur'))");
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('imports_fournitures_lignes');
        Schema::dropIfExists('imports_fournitures');
    }
};
