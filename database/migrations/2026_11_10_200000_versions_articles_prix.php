<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bibliotheque de prix, lot 4 : versions privees et d'entreprise.
 *
 *  - supprime la contrainte « une version privee a toujours un proprietaire » :
 *    quand un utilisateur est supprime, ses versions privees restent (cle
 *    etrangere `nullOnDelete`) et deviennent « orphelines », visibles de la
 *    Direction seule (decision du 10 novembre 2026) ;
 *  - une seule version d'entreprise par article d'origine et par entreprise ;
 *  - une seule version privee par article d'origine et par proprietaire ;
 *    (index partiels : les articles archives ne comptent pas) ;
 *  - index sur l'article d'origine et le proprietaire.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE articles_prix DROP CONSTRAINT IF EXISTS articles_prix_prive_proprietaire_check');

        DB::statement(
            'CREATE UNIQUE INDEX articles_prix_version_entreprise_unique '
            . 'ON articles_prix (tenant_id, article_origine_id) '
            . "WHERE portee = 'entreprise' AND article_origine_id IS NOT NULL AND deleted_at IS NULL"
        );
        DB::statement(
            'CREATE UNIQUE INDEX articles_prix_version_privee_unique '
            . 'ON articles_prix (tenant_id, article_origine_id, proprietaire_id) '
            . "WHERE portee = 'prive' AND article_origine_id IS NOT NULL AND proprietaire_id IS NOT NULL AND deleted_at IS NULL"
        );

        Schema::table('articles_prix', function (Blueprint $table): void {
            $table->index('article_origine_id');
            $table->index('proprietaire_id');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('articles_prix', function (Blueprint $table): void {
            $table->dropIndex(['article_origine_id']);
            $table->dropIndex(['proprietaire_id']);
        });

        DB::statement('DROP INDEX IF EXISTS articles_prix_version_privee_unique');
        DB::statement('DROP INDEX IF EXISTS articles_prix_version_entreprise_unique');

        // NOT VALID : les versions orphelines deja creees ne bloquent pas le retour arriere.
        DB::statement(
            "ALTER TABLE articles_prix ADD CONSTRAINT articles_prix_prive_proprietaire_check "
            . "CHECK (portee IS DISTINCT FROM 'prive' OR proprietaire_id IS NOT NULL) NOT VALID"
        );
    }
};
