<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tâche 2.15 : champs nécessaires au MFA (TOTP).
 *
 * - two_factor_secret : secret TOTP, chiffré en base (cast "encrypted" côté
 *   modèle User) --- jamais stocké ni transmis en clair.
 * - two_factor_recovery_codes : codes de récupération à usage unique,
 *   générés à l'activation, chiffrés en base (JSON chiffré).
 * - two_factor_confirmed_at : NULL tant que l'utilisateur n'a pas confirmé
 *   l'activation avec un premier code valide ; renseignée = MFA actif.
 *   Source de vérité unique (pas de booléen séparé qui pourrait diverger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
