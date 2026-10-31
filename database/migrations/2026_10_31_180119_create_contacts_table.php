<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact / Client (tache 3.4) : racine du modele de donnees, tout le
 * reste (opportunites, devis, chantiers...) s'y rattache par cle etrangere.
 *
 * Une seule adresse (facturation/postale) : l'adresse du chantier
 * appartient au Chantier (tache 3.8), un client recurrent pouvant avoir
 * plusieurs chantiers a des adresses differentes.
 *
 * Pas de colonne "statut client" (prospect/client) : il se deduit de
 * l'historique (devis accepte), pour eviter une donnee desynchronisable.
 * Le statut commercial vit sur l'Opportunite (tache 3.5).
 *
 * tenant_id en string() : tenants.id est un identifiant texte (stancl/tenancy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');

            $table->string('type', 20);
            $table->string('nom')->nullable();
            $table->string('prenom')->nullable();
            $table->string('raison_sociale')->nullable();
            $table->string('siret', 14)->nullable();

            $table->string('adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email')->nullable();

            $table->string('source_lead', 30)->nullable();
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'nom']);
            $table->index(['tenant_id', 'raison_sociale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
