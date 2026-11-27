<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versions du devis (lot V1, décisions du 27 novembre 2026).
 *
 *  - `devis.version_origine_id` : premier devis du groupe (vide pour lui) ;
 *    `rang_version` : 2, 3... (vide pour le premier, qui vaut 1) ;
 *  - `devis.non_retenu_motif`, `non_retenu_par_id`, `non_retenu_le` : pourquoi
 *    et au profit de quelle version le devis est passé à « Non retenue » ;
 *  - `lignes_devis.ligne_origine_id` : ligne dont celle-ci est la copie (sert
 *    au comparatif entre versions).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('devis', function (Blueprint $table): void {
            $table->foreignId('version_origine_id')->nullable()->constrained('devis')->nullOnDelete();
            $table->unsignedSmallInteger('rang_version')->nullable();
            $table->string('non_retenu_motif', 30)->nullable();
            $table->foreignId('non_retenu_par_id')->nullable()->constrained('devis')->nullOnDelete();
            $table->timestamp('non_retenu_le')->nullable();
        });

        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->foreignId('ligne_origine_id')->nullable()->constrained('lignes_devis')->nullOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('lignes_devis', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ligne_origine_id');
        });

        Schema::table('devis', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('non_retenu_par_id');
            $table->dropConstrainedForeignId('version_origine_id');
            $table->dropColumn(['rang_version', 'non_retenu_motif', 'non_retenu_le']);
        });
    }
};
