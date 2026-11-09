<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts (personnes a joindre) du module Clients / CRM. Une personne peut
 * etre rattachee a plusieurs clients (table client_contact). Le droit
 * d'opposition a la prospection est personnel (ne_plus_demarcher_le).
 *
 * tenant_id en string() : tenants.id est un identifiant texte (stancl/tenancy).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');

            $table->string('civilite', 10)->nullable();
            $table->string('prenom')->nullable();
            $table->string('nom');
            $table->string('email')->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('preference_contact', 20)->nullable();
            $table->date('ne_plus_demarcher_le')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'nom']);
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
