<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres du devis (module Devis, décisions du 20 novembre 2026).
 *
 *  - `devis_parametres` : la copie des réglages de l'entreprise pour un devis
 *    (TVA par défaut, marge des fournitures), le coefficient de difficulté et
 *    le coefficient de zone (préparé, toujours 1,00 pour l'instant).
 *  - `devis_taux_horaires` : la copie des taux horaires, ajustable par devis.
 *  - `lignes_devis` : code de l'article copié, marque « prix forcé ».
 *  - `lignes_devis_composants` : temps de base (avant difficulté) et code de
 *    la qualification, pour pouvoir recalculer une ligne.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('devis_parametres', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->decimal('coefficient_difficulte', 4, 2)->default(1);
            $table->decimal('coefficient_zone', 4, 2)->default(1);
            $table->decimal('tva_defaut', 5, 2)->default(20);
            $table->string('tva_source', 20)->default('entreprise');
            $table->decimal('marge_fournitures', 5, 2)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('devis_id');
            $table->index('tenant_id');
        });

        DB::statement(
            'ALTER TABLE devis_parametres ADD CONSTRAINT devis_parametres_difficulte_check '
            . 'CHECK (coefficient_difficulte >= 1 AND coefficient_difficulte <= 1.5)'
        );

        Schema::create('devis_taux_horaires', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle', 100);
            $table->decimal('taux_vente_ht', 10, 2);
            $table->decimal('taux_vente_entreprise', 10, 2);
            $table->decimal('cout_revient_ht', 10, 2)->nullable();
            $table->string('source', 20)->default('entreprise');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['devis_id', 'code']);
            $table->index('tenant_id');
        });

        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->string('code_article')->nullable();
            $table->boolean('prix_force')->default(false);
        });

        Schema::table('lignes_devis_composants', function (Blueprint $table): void {
            $table->decimal('quantite_base', 12, 4)->nullable();
            $table->string('qualification', 30)->nullable();
        });

        // Reprise des lignes existantes.
        DB::statement(
            'UPDATE lignes_devis l SET code_article = a.reference '
            . 'FROM articles_prix a WHERE a.id = l.article_prix_id'
        );
        DB::statement("UPDATE lignes_devis_composants SET quantite_base = quantite WHERE type = 'main_oeuvre'");
        DB::statement(
            'UPDATE lignes_devis_composants c SET qualification = t.code FROM taux_horaires t '
            . "WHERE c.type = 'main_oeuvre' AND t.tenant_id = c.tenant_id AND t.libelle = c.taux_horaire_libelle"
        );
        // Prix forcé = prix de la ligne différent de la somme de son sous-détail.
        DB::statement(
            'UPDATE lignes_devis l SET prix_force = true FROM ('
            . 'SELECT ligne_devis_id, SUM(ROUND(quantite * COALESCE(prix_vente_ht, 0), 2)) AS total '
            . 'FROM lignes_devis_composants GROUP BY ligne_devis_id'
            . ') s WHERE s.ligne_devis_id = l.id AND l.prix_unitaire_ht <> s.total'
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('lignes_devis_composants', function (Blueprint $table): void {
            $table->dropColumn(['quantite_base', 'qualification']);
        });
        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->dropColumn(['code_article', 'prix_force']);
        });
        Schema::dropIfExists('devis_taux_horaires');
        Schema::dropIfExists('devis_parametres');
    }
};
