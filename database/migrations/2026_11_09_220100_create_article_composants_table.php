<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sous-detail d'un article « pour 1 unite » (Bibliotheque de prix, lot 2).
 *
 * Une ligne de main-d'oeuvre n'a ni prix d'achat ni prix de vente : elle cite
 * une qualification (code d'un taux horaire) et le prix est lu dans
 * `taux_horaires` au moment du calcul. Le code est stocke, pas une cle
 * etrangere, pour que les articles de reference (sans entreprise) puissent
 * citer « ouvrier » sans pointer vers le taux d'une entreprise.
 *
 * Contraintes en base (en plus des controles applicatifs) :
 *  - type reconnu ;
 *  - main-d'oeuvre : qualification obligatoire, unite `h`, aucun prix ;
 *  - autre type : prix de vente obligatoire ;
 *  - quantite positive ou nulle.
 *
 * `fourniture_id` n'a pas encore de cle etrangere : la Base de fournitures
 * n'existe pas en V1.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('article_composants', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('article_id')->constrained('articles_prix')->cascadeOnDelete();

            $table->string('type', 20);
            $table->string('qualification', 30)->nullable();
            $table->unsignedBigInteger('fourniture_id')->nullable();
            $table->string('designation');
            $table->decimal('quantite', 12, 4);
            $table->string('unite', 20);
            $table->decimal('prix_achat_ht', 12, 4)->nullable();
            $table->decimal('prix_vente_ht', 12, 4)->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['article_id', 'ordre']);
            $table->index('tenant_id');
        });

        DB::statement(
            "ALTER TABLE article_composants ADD CONSTRAINT article_composants_type_check "
            . "CHECK (type IN ('main_oeuvre','fourniture_base','fourniture_libre','materiel','frais'))"
        );
        DB::statement(
            "ALTER TABLE article_composants ADD CONSTRAINT article_composants_main_oeuvre_check "
            . "CHECK (type <> 'main_oeuvre' OR (qualification IS NOT NULL AND unite = 'h' "
            . "AND prix_achat_ht IS NULL AND prix_vente_ht IS NULL))"
        );
        DB::statement(
            "ALTER TABLE article_composants ADD CONSTRAINT article_composants_prix_check "
            . "CHECK (type = 'main_oeuvre' OR prix_vente_ht IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE article_composants ADD CONSTRAINT article_composants_quantite_check CHECK (quantite >= 0)"
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('article_composants');
    }
};
