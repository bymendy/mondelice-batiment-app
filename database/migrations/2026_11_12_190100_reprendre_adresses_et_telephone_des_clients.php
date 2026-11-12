<?php

use App\Support\Telephone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Clients / CRM, lot O0 :
 * - l'adresse portee par la fiche client (adresse, code_postal, ville) est
 *   copiee dans une adresse de facturation, puis les trois colonnes sont
 *   supprimees : aucune donnee n'est perdue ;
 * - ajout de telephone_normalise (rempli pour les clients existants) et des
 *   index servant a la detection des doublons (e-mail, telephone).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('telephone_normalise', 30)->nullable()->after('telephone');
        });

        DB::table('clients')->whereNotNull('telephone')->chunkById(500, function ($lignes): void {
            foreach ($lignes as $ligne) {
                DB::table('clients')->where('id', $ligne->id)->update([
                    'telephone_normalise' => Telephone::normaliser($ligne->telephone),
                ]);
            }
        });

        DB::statement(
            "INSERT INTO adresses (tenant_id, client_id, type, ligne1, code_postal, ville, pays, created_at, updated_at) "
            . "SELECT tenant_id, id, 'facturation', NULLIF(trim(adresse), ''), NULLIF(trim(code_postal), ''), NULLIF(trim(ville), ''), 'France', now(), now() "
            . "FROM clients "
            . "WHERE NULLIF(trim(coalesce(adresse, '')), '') IS NOT NULL "
            . "OR NULLIF(trim(coalesce(code_postal, '')), '') IS NOT NULL "
            . "OR NULLIF(trim(coalesce(ville, '')), '') IS NOT NULL"
        );

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['adresse', 'code_postal', 'ville']);
        });

        DB::statement('CREATE INDEX clients_tenant_email_lower_index ON clients (tenant_id, lower(email))');
        DB::statement('CREATE INDEX clients_tenant_telephone_normalise_index ON clients (tenant_id, telephone_normalise)');
    }

    /**
     * Restaure les trois colonnes a partir de l'adresse de facturation.
     *
     * @return void
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS clients_tenant_email_lower_index');
        DB::statement('DROP INDEX IF EXISTS clients_tenant_telephone_normalise_index');

        Schema::table('clients', function (Blueprint $table): void {
            $table->string('adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
        });

        DB::statement(
            "UPDATE clients SET adresse = a.ligne1, code_postal = a.code_postal, ville = a.ville "
            . "FROM adresses a WHERE a.client_id = clients.id AND a.type = 'facturation' AND a.deleted_at IS NULL"
        );

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('telephone_normalise');
        });
    }
};
