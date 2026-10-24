<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étend la table `activity_log` (package Spatie Activitylog) pour distinguer
 * une action déclenchée par un humain d'une action déclenchée automatiquement
 * par le futur moteur d'automatisations (Phase 3, Laravel Queue + Redis).
 *
 * Décision éthique à l'origine de cette migration : CONTEXTE_ETHIQUE_BY_DESIGN_IA.md,
 * §3 (« Explicabilité des automatisations ») --- toute action système doit être
 * traçable jusqu'à la règle d'automatisation qui l'a produite (référence AUTO-XX).
 *
 * Point ouvert qu'elle referme : Mondelice_Phase2_Backend.tex, section 6
 * (« Distinction action humaine / action système absente du schéma du journal »).
 */
return new class extends Migration
{
    /**
     * Ajoute les deux colonnes de traçabilité au journal d'actions.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            /**
             * Origine de l'action : 'human' (défaut, comportement actuel
             * inchangé pour connexion/déconnexion/échec) ou 'system' (posée
             * explicitement par le code des automatisations à partir de la Phase 3).
             */
            $table->string('triggered_by', 10)->default('human')->after('tenant_id');

            /**
             * Référence de la règle d'automatisation à l'origine de l'action
             * (ex. "AUTO-03"), nulle pour toute action humaine. Permet de
             * remonter du journal à la règle exacte, sans ambiguïté.
             */
            $table->string('automation_rule', 20)->nullable()->after('triggered_by');
        });
    }

    /**
     * Retire les deux colonnes (rollback).
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn(['triggered_by', 'automation_rule']);
        });
    }
};
