<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Niveau de prestation (tâche 3.6 bis, lot F4a) : une ligne « fourniture de
 * la base » du sous-détail garde sa fourniture standard (`fourniture_id`) et
 * peut recevoir une fourniture économique et une fourniture haut de gamme.
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('article_composants', function (Blueprint $table): void {
            $table->foreignId('fourniture_eco_id')->nullable()->constrained('fournitures')->restrictOnDelete();
            $table->foreignId('fourniture_haut_id')->nullable()->constrained('fournitures')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE article_composants ADD CONSTRAINT article_composants_variantes_check '
            . "CHECK (type = 'fourniture_base' OR (fourniture_eco_id IS NULL AND fourniture_haut_id IS NULL))"
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE article_composants DROP CONSTRAINT IF EXISTS article_composants_variantes_check');

        Schema::table('article_composants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fourniture_eco_id');
            $table->dropConstrainedForeignId('fourniture_haut_id');
        });
    }
};
