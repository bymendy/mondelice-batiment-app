<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bibliotheque de prix : arborescence des categories (lot 1).
 *
 * Trois niveaux en V1 : Domaine > Corps d'etat > Famille d'ouvrages
 * (8 / 48 / 249). La table accepte plus de niveaux.
 *
 * tenant_id est en string() nullable, comme dans articles_prix (la table
 * tenants a un identifiant texte). NULL = categorie de reference partagee par
 * toutes les entreprises ; renseigne = categorie ajoutee par une entreprise.
 *
 * Unicite du code : un code est unique par entreprise, et unique parmi les
 * references. Postgres considere normalement deux NULL comme differents ;
 * NULLS NOT DISTINCT les traite comme egaux, ce qui empeche deux references
 * de porter le meme code. L'index ignore les lignes supprimees (softDeletes)
 * pour qu'un code libere puisse etre reutilise.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('categories_prix', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('categories_prix')->restrictOnDelete();

            $table->unsignedTinyInteger('niveau');
            $table->unsignedSmallInteger('ordre');
            $table->string('code', 30);
            $table->string('nom');

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'parent_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX categories_prix_tenant_code_unique '
            . 'ON categories_prix (tenant_id, code) NULLS NOT DISTINCT WHERE deleted_at IS NULL'
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('categories_prix');
    }
};
