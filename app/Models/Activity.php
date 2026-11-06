<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use RuntimeException;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Modèle de journal d'actions (remplace Spatie\Activitylog\Models\Activity
 * par défaut --- voir config/activitylog.php, clé 'activity_model').
 *
 * Deux garanties ajoutées au comportement standard du package :
 *
 *  1. Isolation multi-tenant : utilise BelongsToTenant comme tous les
 *     autres modèles de l'application --- tenant_id rempli
 *     automatiquement à la création, lectures filtrées par tenant
 *     courant. Peut rester vide (ex. échec de connexion sur un email
 *     inconnu, sans tenant résolu).
 *
 *  2. Écriture seule (append-only) : update() et delete() sont bloqués
 *     ici, en plus du trigger PostgreSQL (voir la migration
 *     *_add_append_only_trigger_to_activity_log_table.php) qui impose
 *     la même règle au niveau base de données --- décision actée dans
 *     CONTEXTE_SECURITE_RGPD_IA.md, point 3 : le journal doit avoir
 *     valeur de preuve en cas de litige, donc ne peut jamais être
 *     modifié ni supprimé, même par erreur applicative ou accès direct
 *     à la base.
 */
class Activity extends SpatieActivity
{
    use BelongsToTenant;

    /**
     * Bloque toute tentative de modification d'une entrée existante.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     * @throws RuntimeException Toujours --- le journal est en écriture seule.
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException(
            "Le journal d'actions est en écriture seule (append-only) : une entrée ne peut jamais être modifiée. Une erreur se corrige par une nouvelle entrée, jamais par une modification de l'existante."
        );
    }

    /**
     * Bloque toute tentative de suppression d'une entrée existante.
     *
     * @throws RuntimeException Toujours --- le journal est en écriture seule.
     */
    public function delete()
    {
        throw new RuntimeException(
            "Le journal d'actions est en écriture seule (append-only) : une entrée ne peut jamais être supprimée, y compris par le compte admin système."
        );
    }

    /**
     * Exempte du refus par defaut du trait BelongsToTenant : ce modele doit
     * pouvoir etre lu avant l'identification de l'entreprise (le journal est ecrit et lu pendant la connexion).
     *
     * @return bool
     */
    public static function filtreTenantStrict(): bool
    {
        return false;
    }
}
