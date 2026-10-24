<?php

use App\Models\Activity;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * NOTE PROJET (15/10/2026) : cette valeur par défaut du package est
     * sans effet ici --- la commande activitylog:clean échouerait de
     * toute façon, bloquée par le trigger PostgreSQL append-only (voir
     * migration *_add_append_only_trigger_to_activity_log_table.php) et
     * par App\Models\Activity::delete(). Décision actée dans
     * CONTEXTE_SECURITE_RGPD_IA.md (point 3) : le journal d'actions ne
     * doit jamais être supprimé, y compris après un délai. Elle n'est
     * donc jamais planifiée (voir routes/console.php).
     */
    'clean_after_days' => 365,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject relationship on activities
     * will include soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * Modèle personnalisé (App\Models\Activity) --- ajoute l'isolation
     * multi-tenant (BelongsToTenant) et bloque update()/delete() au
     * niveau applicatif, en plus du trigger PostgreSQL. Voir
     * app/Models/Activity.php.
     */
    'activity_model' => Activity::class,

    /*
     * These attributes will be excluded from logging for all models.
     * Model-specific exclusions via logExcept() are merged with these.
     */
    'default_except_attributes' => [],

    /*
     * When enabled, activities are buffered in memory and inserted in a
     * single bulk query after the response has been sent to the client.
     * This can significantly reduce the number of database queries when
     * many activities are logged during a single request.
     *
     * Only enable this if your application logs a high volume of activities
     * per request. Buffered activities will not have an ID until the
     * buffer is flushed.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * These action classes can be overridden to customize how activities
     * are logged and cleaned. Your custom classes must extend the originals.
     */
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
