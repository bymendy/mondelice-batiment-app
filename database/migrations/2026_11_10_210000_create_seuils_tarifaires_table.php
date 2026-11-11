<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seuils planchers de l'entreprise, fixes par la Direction (Parametres du
 * compte, § 4.2 et 4.6). Premier usage : controle du prix force d'un article de
 * la Bibliotheque de prix (lot 6).
 *
 * Un seuil par type et par entreprise. La valeur est un pourcentage de marge sur
 * le prix de vente : de 0 a moins de 100. Un seuil inactif est ignore.
 *
 * Les types acceptes sont limites a ceux que l'application controle deja ; la
 * contrainte sera elargie par migration quand un nouveau type sera controle.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('seuils_tarifaires', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('type', 40);
            $table->decimal('valeur', 5, 2);
            $table->boolean('actif')->default(true);
            $table->foreignId('defini_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'type']);
        });

        DB::statement(
            "ALTER TABLE seuils_tarifaires ADD CONSTRAINT seuils_tarifaires_type_check "
            . "CHECK (type IN ('marge_mo_min','marge_fournitures_min'))"
        );
        DB::statement(
            'ALTER TABLE seuils_tarifaires ADD CONSTRAINT seuils_tarifaires_valeur_check '
            . 'CHECK (valeur >= 0 AND valeur < 100)'
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('seuils_tarifaires');
    }
};
