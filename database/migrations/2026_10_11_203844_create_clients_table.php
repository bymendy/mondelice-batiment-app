<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des clients (CRM).
 *
 * Un client peut être une personne physique ou une entreprise (colonne
 * `type`). Rattaché à un tenant via `tenant_id`, comme toutes les tables
 * métier de l'application (voir app/Models/Concerns/BelongsToTenant.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->enum('type', ['particulier', 'entreprise'])->default('particulier');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('siret')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
