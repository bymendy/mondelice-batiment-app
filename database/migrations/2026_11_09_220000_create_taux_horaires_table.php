<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taux horaires de l'entreprise (Bibliotheque de prix, lot 2).
 *
 * Une ligne par qualification (ouvrier, technicien, chef d'equipe...). Le
 * `code` est la cle stable utilisee par les lignes de main-d'oeuvre des
 * articles (colonne `qualification`) : renommer le libelle ne casse rien.
 *
 * cout_revient_ht (salaire charge) est reserve a la Direction cote API.
 *
 * Un seul taux « par defaut » par entreprise (index partiel).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('taux_horaires', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');

            $table->string('code', 30);
            $table->string('libelle', 100);
            $table->decimal('taux_vente_ht', 10, 2);
            $table->decimal('cout_revient_ht', 10, 2)->nullable();
            $table->boolean('par_defaut')->default(false);
            $table->unsignedSmallInteger('ordre')->default(0);

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX taux_horaires_un_defaut_par_tenant ON taux_horaires (tenant_id) WHERE par_defaut'
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('taux_horaires');
    }
};
