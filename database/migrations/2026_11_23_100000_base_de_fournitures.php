<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base de fournitures (tâche 3.6 bis, lot F1, décisions du 23 novembre 2026).
 *
 *  - La table `suppliers` de la phase 1 devient `fournisseurs` (colonnes en
 *    français, `tenant_id` aligné sur les autres tables, remise par défaut,
 *    archivage). `purchase_orders.supplier_id` continue de pointer dessus.
 *  - `fournitures` : les fournitures réutilisables de l'entreprise, avec prix
 *    tarif, remise, prix d'achat net et prix de vente.
 *  - `article_composants.fourniture_id` reçoit sa clé étrangère ;
 *    `lignes_devis_composants` garde le lien vers la fourniture.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $orphelins = (int) DB::table('suppliers')
            ->where(function ($q): void {
                $q->whereNull('tenant_id')
                    ->orWhereRaw('tenant_id::text NOT IN (SELECT id::text FROM tenants)');
            })
            ->count();
        if ($orphelins > 0) {
            throw new RuntimeException(
                "{$orphelins} fournisseur(s) sans entreprise dans la table suppliers : à corriger avant la migration."
            );
        }

        Schema::rename('suppliers', 'fournisseurs');
        DB::statement('DROP INDEX IF EXISTS suppliers_tenant_id_index');
        DB::statement('ALTER TABLE fournisseurs ALTER COLUMN tenant_id TYPE varchar(255) USING tenant_id::text');
        DB::statement('ALTER TABLE fournisseurs ALTER COLUMN tenant_id SET NOT NULL');

        Schema::table('fournisseurs', function (Blueprint $table): void {
            $table->renameColumn('name', 'nom');
            $table->renameColumn('contact_name', 'contact_nom');
            $table->renameColumn('phone', 'telephone');
            $table->renameColumn('address', 'adresse');
        });

        Schema::table('fournisseurs', function (Blueprint $table): void {
            $table->string('reference', 50)->nullable();
            $table->decimal('remise_defaut_pct', 5, 2)->default(0);
            $table->boolean('actif')->default(true);
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        DB::statement(
            'ALTER TABLE fournisseurs ADD CONSTRAINT fournisseurs_remise_check '
            . 'CHECK (remise_defaut_pct >= 0 AND remise_defaut_pct <= 100)'
        );

        Schema::create('fournitures', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->restrictOnDelete();
            $table->string('reference', 80)->nullable();
            $table->string('designation');
            $table->string('unite', 20);
            $table->decimal('prix_tarif_ht', 12, 4)->nullable();
            $table->decimal('remise_pct', 5, 2)->nullable();
            $table->decimal('prix_achat_ht', 12, 4)->nullable();
            $table->decimal('prix_vente_ht', 12, 4);
            $table->boolean('prix_vente_saisi')->default(true);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'designation']);
        });

        DB::statement(
            'ALTER TABLE fournitures ADD CONSTRAINT fournitures_prix_check CHECK ('
            . 'prix_vente_ht >= 0 AND (prix_achat_ht IS NULL OR prix_achat_ht >= 0) '
            . 'AND (prix_tarif_ht IS NULL OR prix_tarif_ht >= 0) '
            . 'AND (remise_pct IS NULL OR (remise_pct >= 0 AND remise_pct <= 100)))'
        );
        // Une référence n'existe qu'une fois par fournisseur (fournitures non archivées).
        DB::statement(
            'CREATE UNIQUE INDEX fournitures_reference_unique ON fournitures '
            . '(tenant_id, COALESCE(fournisseur_id, 0), reference) '
            . 'WHERE reference IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::table('article_composants', function (Blueprint $table): void {
            $table->foreign('fourniture_id')->references('id')->on('fournitures')->restrictOnDelete();
            $table->index('fourniture_id');
        });

        Schema::table('lignes_devis_composants', function (Blueprint $table): void {
            $table->foreignId('fourniture_id')->nullable()->constrained('fournitures')->nullOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('lignes_devis_composants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fourniture_id');
        });
        Schema::table('article_composants', function (Blueprint $table): void {
            $table->dropForeign(['fourniture_id']);
            $table->dropIndex(['fourniture_id']);
        });
        Schema::dropIfExists('fournitures');

        DB::statement('ALTER TABLE fournisseurs DROP CONSTRAINT IF EXISTS fournisseurs_remise_check');
        Schema::table('fournisseurs', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn(['reference', 'remise_defaut_pct', 'actif', 'deleted_at']);
        });
        Schema::table('fournisseurs', function (Blueprint $table): void {
            $table->renameColumn('nom', 'name');
            $table->renameColumn('contact_nom', 'contact_name');
            $table->renameColumn('telephone', 'phone');
            $table->renameColumn('adresse', 'address');
        });
        DB::statement('ALTER TABLE fournisseurs ALTER COLUMN tenant_id DROP NOT NULL');
        DB::statement('ALTER TABLE fournisseurs ALTER COLUMN tenant_id TYPE uuid USING tenant_id::uuid');
        Schema::rename('fournisseurs', 'suppliers');
        DB::statement('CREATE INDEX suppliers_tenant_id_index ON suppliers (tenant_id)');
    }
};
