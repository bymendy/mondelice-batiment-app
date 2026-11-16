<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Devis, lot D0 : les tables de la Phase 1 `quotes` et `quote_lines`
 * deviennent `devis` et `lignes_devis`, alignees sur le modele du module
 * Devis, et la table `lots_devis` apparait.
 *
 * - renomme aussi sequences, index et cles etrangeres ;
 * - tenant_id passe de uuid a texte avec cle etrangere vers tenants (comme
 *   `clients`) ; les lignes sans entreprise la reprennent de leur client ;
 * - colonnes renommees : numero, statut, date_devis, date_expiration,
 *   total_tva, accepte_le, opportunite_id ; libelle, quantite, unite,
 *   prix_unitaire_ht, taux_tva, ordre, devis_id ;
 * - nouvelles colonnes du devis : avenants, titre, adresse de chantier,
 *   validite, portee, auteur, dates d'envoi, preuve d'acceptation,
 *   suppression douce ; des lignes : lot, emplacement, descriptif, DTU,
 *   expression de metre, prix de revient ;
 * - le statut « devis_en_preparation » devient « en_preparation ».
 *
 * Les donnees existantes sont conservees. La table construction_sites garde
 * sa colonne quote_id (elle sera reprise avec la tache des chantiers).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        // --- devis ------------------------------------------------------
        DB::statement('ALTER TABLE quotes RENAME TO devis');
        DB::statement('ALTER SEQUENCE IF EXISTS quotes_id_seq RENAME TO devis_id_seq');
        DB::statement('ALTER INDEX IF EXISTS quotes_pkey RENAME TO devis_pkey');
        DB::statement('ALTER INDEX IF EXISTS quotes_tenant_id_index RENAME TO devis_tenant_id_index');
        DB::statement('ALTER TABLE devis RENAME CONSTRAINT quotes_client_id_foreign TO devis_client_id_foreign');
        DB::statement('ALTER TABLE devis RENAME CONSTRAINT quotes_opportunity_id_foreign TO devis_opportunite_id_foreign');

        DB::statement('ALTER TABLE devis ALTER COLUMN tenant_id TYPE varchar(255) USING tenant_id::text');
        DB::statement('UPDATE devis d SET tenant_id = c.tenant_id FROM clients c WHERE c.id = d.client_id AND d.tenant_id IS NULL');

        DB::statement('ALTER TABLE devis RENAME COLUMN opportunity_id TO opportunite_id');
        DB::statement('ALTER TABLE devis RENAME COLUMN quote_number TO numero');
        DB::statement('ALTER TABLE devis RENAME COLUMN status TO statut');
        DB::statement('ALTER TABLE devis RENAME COLUMN issued_at TO date_devis');
        DB::statement('ALTER TABLE devis RENAME COLUMN valid_until TO date_expiration');
        DB::statement('ALTER TABLE devis RENAME COLUMN total_vat TO total_tva');
        DB::statement('ALTER TABLE devis RENAME COLUMN signed_at TO accepte_le');

        DB::statement("ALTER TABLE devis ALTER COLUMN statut SET DEFAULT 'en_preparation'");
        DB::statement("UPDATE devis SET statut = 'en_preparation' WHERE statut = 'devis_en_preparation'");

        Schema::table('devis', function (Blueprint $table): void {
            $table->foreignId('devis_parent_id')->nullable()->constrained('devis')->restrictOnDelete();
            $table->unsignedSmallInteger('rang_avenant')->nullable();
            $table->string('titre')->nullable();
            $table->foreignId('adresse_chantier_id')->nullable()->constrained('adresses')->nullOnDelete();
            $table->unsignedSmallInteger('validite_jours')->default(30);
            $table->string('portee', 20)->default('prive');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('envoye_le')->nullable();
            $table->string('preuve_acceptation')->nullable();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE devis ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement("ALTER TABLE devis ADD CONSTRAINT devis_portee_check CHECK (portee IN ('prive', 'entreprise'))");
        DB::statement('ALTER TABLE devis ADD CONSTRAINT devis_validite_check CHECK (validite_jours > 0)');
        DB::statement('CREATE UNIQUE INDEX devis_tenant_numero_unique ON devis (tenant_id, numero) WHERE numero IS NOT NULL');

        Schema::table('devis', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'statut']);
            $table->index(['tenant_id', 'client_id']);
        });

        // --- lots_devis -------------------------------------------------
        Schema::create('lots_devis', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->string('type', 20)->default('lot');
            $table->string('libelle');
            $table->unsignedInteger('ordre')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['devis_id', 'ordre']);
        });
        DB::statement("ALTER TABLE lots_devis ADD CONSTRAINT lots_devis_type_check CHECK (type IN ('lot', 'emplacement'))");

        // --- lignes_devis -----------------------------------------------
        DB::statement('ALTER TABLE quote_lines RENAME TO lignes_devis');
        DB::statement('ALTER SEQUENCE IF EXISTS quote_lines_id_seq RENAME TO lignes_devis_id_seq');
        DB::statement('ALTER INDEX IF EXISTS quote_lines_pkey RENAME TO lignes_devis_pkey');
        DB::statement('ALTER INDEX IF EXISTS quote_lines_tenant_id_index RENAME TO lignes_devis_tenant_id_index');
        DB::statement('ALTER TABLE lignes_devis RENAME CONSTRAINT quote_lines_quote_id_foreign TO lignes_devis_devis_id_foreign');
        DB::statement('ALTER TABLE lignes_devis RENAME CONSTRAINT quote_lines_article_prix_id_foreign TO lignes_devis_article_prix_id_foreign');

        DB::statement('ALTER TABLE lignes_devis ALTER COLUMN tenant_id TYPE varchar(255) USING tenant_id::text');
        DB::statement('UPDATE lignes_devis l SET tenant_id = d.tenant_id FROM devis d WHERE d.id = l.quote_id AND l.tenant_id IS NULL');

        DB::statement('ALTER TABLE lignes_devis RENAME COLUMN quote_id TO devis_id');
        DB::statement('ALTER TABLE lignes_devis RENAME COLUMN designation TO libelle');
        DB::statement('ALTER TABLE lignes_devis RENAME COLUMN unit TO unite');
        DB::statement('ALTER TABLE lignes_devis RENAME COLUMN quantity TO quantite');
        DB::statement('ALTER TABLE lignes_devis RENAME COLUMN unit_price_ht TO prix_unitaire_ht');
        DB::statement('ALTER TABLE lignes_devis RENAME COLUMN vat_rate TO taux_tva');
        DB::statement('ALTER TABLE lignes_devis RENAME COLUMN position TO ordre');
        DB::statement('ALTER TABLE lignes_devis ALTER COLUMN quantite TYPE numeric(12,4)');

        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->foreignId('lot_id')->nullable()->constrained('lots_devis')->nullOnDelete();
            $table->foreignId('emplacement_id')->nullable()->constrained('lots_devis')->nullOnDelete();
            $table->text('descriptif')->nullable();
            $table->string('dtu_reference')->nullable();
            $table->string('expression_metre')->nullable();
            $table->decimal('prix_revient_unitaire', 10, 2)->nullable();
        });

        DB::statement('ALTER TABLE lignes_devis ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement('ALTER TABLE lignes_devis ADD CONSTRAINT lignes_devis_taux_tva_check CHECK (taux_tva BETWEEN 0 AND 100)');

        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['devis_id', 'ordre']);
        });
    }

    /**
     * Migration volontairement irreversible : des colonnes et des donnees
     * n'ont pas d'equivalent dans les anciennes tables.
     *
     * @return void
     *
     * @throws RuntimeException Toujours.
     */
    public function down(): void
    {
        throw new RuntimeException('Migration irreversible : `devis` remplace `quotes`.');
    }
};
