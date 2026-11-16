<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sous-detail fige d'une ligne de devis (module Devis, lot D3).
 *
 * Memes colonnes que article_composants, plus les valeurs du taux horaire
 * copiees au moment de l'ajout : les totaux d'une ligne ne dependent plus
 * jamais de la bibliotheque ni des taux horaires.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('lignes_devis_composants', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('ligne_devis_id')->constrained('lignes_devis')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('designation');
            $table->decimal('quantite', 12, 4);
            $table->string('unite', 20);
            $table->decimal('prix_achat_ht', 12, 4)->nullable();
            $table->decimal('prix_vente_ht', 12, 4)->nullable();
            $table->string('taux_horaire_libelle')->nullable();
            $table->decimal('taux_horaire_vente', 10, 2)->nullable();
            $table->decimal('cout_revient_horaire', 10, 2)->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['ligne_devis_id', 'ordre']);
            $table->index('tenant_id');
        });

        DB::statement(
            "ALTER TABLE lignes_devis_composants ADD CONSTRAINT lignes_devis_composants_type_check "
            . "CHECK (type IN ('main_oeuvre','fourniture_base','fourniture_libre','materiel','frais'))"
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('lignes_devis_composants');
    }
};
