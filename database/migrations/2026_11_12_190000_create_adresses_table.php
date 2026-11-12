<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Clients / CRM, lot O0 : table des adresses d'un client (une de
 * facturation, plusieurs de chantier). Meme convention que `clients` :
 * tenant_id texte avec cle etrangere vers tenants.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('adresses', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('libelle', 100)->nullable();
            $table->string('ligne1')->nullable();
            $table->string('ligne2')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 60)->default('France');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('infos_acces')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'code_postal']);
        });

        DB::statement("ALTER TABLE adresses ADD CONSTRAINT adresses_type_check CHECK (type IN ('facturation', 'chantier'))");
        DB::statement(
            "CREATE UNIQUE INDEX adresses_une_facturation_par_client ON adresses (client_id) "
            . "WHERE type = 'facturation' AND deleted_at IS NULL"
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('adresses');
    }
};
