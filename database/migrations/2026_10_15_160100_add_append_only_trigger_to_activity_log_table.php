<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Impose au niveau base de données la politique de journal d'actions
 * actée dans CONTEXTE_SECURITE_RGPD_IA.md (point 3) : écriture seule
 * (append-only), aucune modification ni suppression possible --- y
 * compris par le compte admin système, et même en cas de bug
 * applicatif ou d'accès direct psql. Le modèle App\Models\Activity
 * bloque déjà update()/delete() côté applicatif ; ce trigger est la
 * garantie de dernier recours, au niveau base de données elle-même.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_activity_log_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Le journal d''actions (activity_log) est en ecriture seule : UPDATE et DELETE sont interdits, y compris pour le compte admin systeme.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER activity_log_append_only
                BEFORE UPDATE OR DELETE ON activity_log
                FOR EACH ROW
                EXECUTE FUNCTION prevent_activity_log_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS activity_log_append_only ON activity_log;
            DROP FUNCTION IF EXISTS prevent_activity_log_mutation();
        SQL);
    }
};
