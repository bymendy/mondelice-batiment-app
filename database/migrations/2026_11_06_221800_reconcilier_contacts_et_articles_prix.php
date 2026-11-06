<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tache 3.4 ter : reconciliation des tables de la Phase 1 avec la Phase 3.
 *
 * La Phase 1 avait cree `clients` et `price_library_items` ; la Phase 3 a
 * cree `contacts` et `articles_prix` pour les memes entites (doublon, contraire
 * au principe "aucune double saisie"). Cette migration :
 *  - repointe opportunities.client_id et quotes.client_id vers `contacts`
 *    (colonne renommee contact_id) ;
 *  - repointe quote_lines.price_library_item_id vers `articles_prix`
 *    (colonne renommee article_prix_id) ;
 *  - supprime les tables `clients` et `price_library_items`.
 *
 * @throws RuntimeException Si l'une des tables concernees contient des
 *         donnees : la migration refuse alors de s'executer pour ne rien perdre.
 */
return new class extends Migration
{
    /**
     * Tables qui doivent etre vides pour que la reconciliation soit sans perte.
     *
     * @var list<string>
     */
    private array $tablesAVerifier = [
        'clients',
        'price_library_items',
        'opportunities',
        'quotes',
        'quote_lines',
    ];

    /**
     * @return void
     *
     * @throws RuntimeException Si une table concernee contient des donnees.
     */
    public function up(): void
    {
        foreach ($this->tablesAVerifier as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    "La table `{$table}` contient des donnees : reconciliation refusee pour eviter une perte. "
                    . 'Migrez ces donnees vers contacts / articles_prix avant de relancer.'
                );
            }
        }

        // Opportunites : client_id -> contact_id (vers contacts).
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });
        Schema::table('opportunities', function (Blueprint $table) {
            $table->renameColumn('client_id', 'contact_id');
        });
        Schema::table('opportunities', function (Blueprint $table) {
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        // Devis : client_id -> contact_id (vers contacts).
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->renameColumn('client_id', 'contact_id');
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        // Lignes de devis : price_library_item_id -> article_prix_id (vers articles_prix).
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropForeign(['price_library_item_id']);
        });
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->renameColumn('price_library_item_id', 'article_prix_id');
        });
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->foreign('article_prix_id')->references('id')->on('articles_prix')->nullOnDelete();
        });

        Schema::dropIfExists('price_library_items');
        Schema::dropIfExists('clients');
    }

    /**
     * Migration volontairement irreversible : les tables supprimees etaient
     * vides et remplacees par `contacts` et `articles_prix`.
     *
     * @return void
     *
     * @throws RuntimeException Toujours.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Migration irreversible : `clients` et `price_library_items` sont remplacees par `contacts` et `articles_prix`.'
        );
    }
};
