<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bibliotheque de prix, lot 3 : extension de `articles_prix`.
 *
 *  - `categorie` (enum) devient `type_article` ;
 *  - `famille` et `sous_famille` (texte) sont supprimes : `categorie_id`
 *    pointe vers l'arborescence `categories_prix` (vide pour les articles
 *    existants, a rattacher a la main) ;
 *  - `prix_vente_ht` devient `prix_force_ht`, facultatif : le prix d'un article
 *    est calcule depuis son sous-detail. Un article existant garde donc son
 *    ancien prix de vente comme prix force ;
 *  - `prix_achat_ht` disparait : le revient se calcule depuis le sous-detail ;
 *  - `tenant_id` devient facultatif (NULL = article de reference, partage) et
 *    l'unicite (tenant_id, reference) traite les NULL comme egaux
 *    (NULLS NOT DISTINCT) ;
 *  - nouvelles colonnes : portee, proprietaire, article d'origine, source,
 *    version, publication, date du prix, DTU de reference et son lien ;
 *  - `article_composants.tenant_id` devient facultatif lui aussi, pour que la
 *    composition d'un article de reference soit lisible.
 *
 * Migration irreversible : des colonnes de donnees sont supprimees.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        // Index et unicite a recreer apres les renommages.
        Schema::table('articles_prix', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'categorie']);
            $table->dropUnique(['tenant_id', 'reference']);
        });

        Schema::table('articles_prix', function (Blueprint $table): void {
            $table->renameColumn('categorie', 'type_article');
            $table->renameColumn('prix_vente_ht', 'prix_force_ht');
        });

        Schema::table('articles_prix', function (Blueprint $table): void {
            $table->string('tenant_id')->nullable()->change();
            $table->decimal('prix_force_ht', 10, 2)->nullable()->change();
        });

        Schema::table('articles_prix', function (Blueprint $table): void {
            $table->dropColumn(['prix_achat_ht', 'famille', 'sous_famille']);
        });

        Schema::table('articles_prix', function (Blueprint $table): void {
            $table->foreignId('categorie_id')->nullable()->constrained('categories_prix')->nullOnDelete();
            $table->string('portee', 12)->nullable();
            $table->foreignId('proprietaire_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('article_origine_id')->nullable()->constrained('articles_prix')->nullOnDelete();
            $table->foreignId('article_source_id')->nullable()->constrained('articles_prix')->nullOnDelete();
            $table->string('source', 30)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('publie_le')->nullable();
            $table->timestamp('prix_mis_a_jour_le')->nullable();
            $table->string('dtu_reference', 120)->nullable();
            $table->string('dtu_lien', 500)->nullable();

            $table->index(['tenant_id', 'type_article']);
            $table->index(['tenant_id', 'categorie_id']);
        });

        // Articles existants : tous appartiennent a une entreprise.
        DB::table('articles_prix')->whereNotNull('tenant_id')->update([
            'portee' => 'entreprise',
            'prix_mis_a_jour_le' => DB::raw('updated_at'),
        ]);

        DB::statement(
            'CREATE UNIQUE INDEX articles_prix_tenant_reference_unique '
            . 'ON articles_prix (tenant_id, reference) NULLS NOT DISTINCT'
        );
        DB::statement(
            "ALTER TABLE articles_prix ADD CONSTRAINT articles_prix_type_article_check "
            . "CHECK (type_article IN ('materiaux','main_oeuvre','sous_traitance','forfait'))"
        );
        DB::statement(
            "ALTER TABLE articles_prix ADD CONSTRAINT articles_prix_portee_check "
            . "CHECK (portee IS NULL OR portee IN ('prive','entreprise'))"
        );
        DB::statement(
            "ALTER TABLE articles_prix ADD CONSTRAINT articles_prix_prive_proprietaire_check "
            . "CHECK (portee IS DISTINCT FROM 'prive' OR proprietaire_id IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE articles_prix ADD CONSTRAINT articles_prix_reference_sans_portee_check "
            . "CHECK (tenant_id IS NOT NULL OR portee IS NULL)"
        );

        Schema::table('article_composants', function (Blueprint $table): void {
            $table->string('tenant_id')->nullable()->change();
        });
    }

    /**
     * @return void
     *
     * @throws RuntimeException Toujours.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Migration irreversible : des colonnes de donnees (prix_achat_ht, famille, sous_famille) sont supprimees.'
        );
    }
};
