<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot D4b (2e partie) :
 *  - `notifications` : table standard de Laravel pour les alertes à l'écran ;
 *  - `alertes_seuils` : une alerte regroupe les refus d'une même personne sur
 *    un même devis et un même seuil pendant le délai réglé par la Direction.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alertes_seuils', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type_seuil', 40);
            $table->string('moment', 20);
            $table->unsignedInteger('nombre')->default(1);
            $table->decimal('seuil', 5, 2);
            $table->decimal('derniere_valeur', 8, 2);
            $table->decimal('pire_valeur', 8, 2);
            $table->timestamp('premiere_le');
            $table->timestamp('derniere_le');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'devis_id', 'user_id', 'type_seuil', 'premiere_le'], 'alertes_seuils_regroupement_index');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('alertes_seuils');
        Schema::dropIfExists('notifications');
    }
};
