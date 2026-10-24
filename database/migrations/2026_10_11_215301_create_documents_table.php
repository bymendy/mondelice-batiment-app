<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des documents.
 *
 * Table polymorphique : un document peut être attaché à n'importe quelle
 * autre entité (client, devis, chantier, ticket SAV...) via les colonnes
 * documentable_type/documentable_id, plutôt que de dupliquer une table
 * "documents" par entité. Le fichier physique sera stocké en S3-compatible
 * (Scaleway/OVHcloud, voir stack) ; cette table n'en garde que la référence.
 * Statuts (section 5.3 du cadrage) : À déposer -> Reçu -> Validé -> Manquant
 * -> Refusé -> Archivé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->morphs('documentable'); // documentable_type + documentable_id
            $table->string('category')->nullable(); // ex: photo_chantier, devis_pdf, piece_identite
            $table->string('original_name');
            $table->string('path'); // chemin dans le stockage S3-compatible
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status')->default('recu');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
