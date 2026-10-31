<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglages par entreprise cliente (tache 3.4 bis) : une ligne par couple
 * (tenant, cle). Sert d'abord a l'interrupteur "le Commercial peut
 * modifier la Bibliotheque de prix", puis a l'option de couleur du devis.
 *
 * Les cles autorisees et leurs valeurs par defaut sont declarees dans
 * App\Models\Parametre::DEFINITIONS, pas en base : une entreprise sans
 * ligne pour une cle utilise la valeur par defaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('cle', 100);
            $table->json('valeur');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'cle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
