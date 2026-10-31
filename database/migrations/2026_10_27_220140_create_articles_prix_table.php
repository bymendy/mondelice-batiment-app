<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bibliotheque de prix (tache 3.3) : catalogue d'articles/ouvrages
 * reutilisable, reference par les lignes de devis (jamais duplique --
 * seule la ligne de devis signee fige le prix au moment de la signature).
 *
 * tenant_id est en string() et non en foreignId() : la table tenants
 * (posee par stancl/tenancy en Phase 1) utilise un identifiant texte
 * comme cle primaire, pas un entier auto-incremente.
 *
 * Pas de colonne pour le taux de marge : calcule a la volee depuis
 * prix_achat_ht et prix_vente_ht (ArticlePrix::tauxMarge()), decision du
 * 25/10/2026 pour eviter toute desynchronisation entre les trois valeurs.
 *
 * fournisseur est un champ texte libre pour l'instant (recherche simple) :
 * deviendra une vraie relation vers une table fournisseurs a la tache 3.10
 * (module Achats/Fournisseurs), sans perte de donnees a la migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles_prix', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');

            $table->string('reference', 50);
            $table->string('designation');
            $table->text('description')->nullable();

            $table->string('categorie', 30);
            $table->string('famille', 100)->nullable();
            $table->string('sous_famille', 100)->nullable();

            $table->string('unite', 10);
            $table->decimal('prix_achat_ht', 10, 2)->nullable();
            $table->decimal('prix_vente_ht', 10, 2);
            $table->decimal('taux_tva', 5, 2)->default(20.00);

            $table->string('fournisseur')->nullable();
            $table->boolean('actif')->default(true);

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'categorie']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles_prix');
    }
};
