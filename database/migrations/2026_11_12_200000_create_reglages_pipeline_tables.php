<?php

use App\Services\Crm\InitialisationCrm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Clients / CRM, lot O1 : reglages du pipeline par entreprise.
 * - sources_leads : d'ou vient un client ou une affaire ;
 * - etapes_pipeline : les dix etapes (systeme, identifiees par leur code) ;
 * - motifs : motifs de perte et d'ecart de demande.
 * Les entreprises existantes recoivent leurs reglages par defaut.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('sources_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('code', 50)->nullable();
            $table->string('nom', 100);
            $table->boolean('systeme')->default(false);
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'ordre']);
        });
        DB::statement('CREATE UNIQUE INDEX sources_leads_tenant_code_unique ON sources_leads (tenant_id, code) WHERE code IS NOT NULL');

        Schema::create('etapes_pipeline', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('code', 30);
            $table->string('nom', 60);
            $table->boolean('systeme')->default(true);
            $table->unsignedSmallInteger('ordre');
            $table->unsignedSmallInteger('probabilite_defaut');
            $table->string('type', 20);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'ordre']);
        });
        DB::statement('ALTER TABLE etapes_pipeline ADD CONSTRAINT etapes_pipeline_probabilite_check CHECK (probabilite_defaut BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE etapes_pipeline ADD CONSTRAINT etapes_pipeline_type_check CHECK (type IN ('ouverte', 'gagnee', 'perdue', 'sans_suite'))");

        Schema::create('motifs', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('type', 20);
            $table->string('libelle', 100);
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'type', 'ordre']);
        });
        DB::statement("ALTER TABLE motifs ADD CONSTRAINT motifs_type_check CHECK (type IN ('perte', 'ecart_demande'))");

        foreach (DB::table('tenants')->pluck('id') as $entrepriseId) {
            InitialisationCrm::pour((string) $entrepriseId);
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('motifs');
        Schema::dropIfExists('etapes_pipeline');
        Schema::dropIfExists('sources_leads');
    }
};
