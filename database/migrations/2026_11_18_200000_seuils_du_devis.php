<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot D4b : la table des seuils accepte deux types de plus, controles sur le
 * devis : `marge_globale_min` (marge globale minimum) et `remise_max` (remise
 * maximum, en % du total HT avant remises).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE seuils_tarifaires DROP CONSTRAINT seuils_tarifaires_type_check');
        DB::statement(
            "ALTER TABLE seuils_tarifaires ADD CONSTRAINT seuils_tarifaires_type_check "
            . "CHECK (type IN ('marge_mo_min','marge_fournitures_min','marge_globale_min','remise_max'))"
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        DB::table('seuils_tarifaires')->whereIn('type', ['marge_globale_min', 'remise_max'])->delete();
        DB::statement('ALTER TABLE seuils_tarifaires DROP CONSTRAINT seuils_tarifaires_type_check');
        DB::statement(
            "ALTER TABLE seuils_tarifaires ADD CONSTRAINT seuils_tarifaires_type_check "
            . "CHECK (type IN ('marge_mo_min','marge_fournitures_min'))"
        );
    }
};
