<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default storage connection
    |--------------------------------------------------------------------------
    |
    | Database connection name used by laravel-flow when persisting flow runs
    | and audit rows. Set to null to inherit the application default. Reserved
    | for v0.2 (in v0.1 the engine is in-memory only — execution does not
    | touch the database).
    |
    */
    'default_storage' => env('LARAVEL_FLOW_STORAGE', null),

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | When true, every flow run + step transition emits a Laravel event the
    | host application can subscribe to. v0.2 will additionally persist the
    | trail to a `flow_audit` table.
    |
    */
    'audit_trail_enabled' => env('LARAVEL_FLOW_AUDIT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Dry-run default
    |--------------------------------------------------------------------------
    |
    | When true, Flow::execute() with no explicit dry-run flag still simulates
    | (i.e. caller must opt INTO real persistence). Guard rail for staging
    | environments.
    |
    */
    'dry_run_default' => env('LARAVEL_FLOW_DRY_RUN_DEFAULT', false),

    /*
    |--------------------------------------------------------------------------
    | Step timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Reserved for v0.2 — when a step runs in a queued worker the wrapper job
    | will use this as its timeout. Currently informational only.
    |
    */
    'step_timeout_seconds' => (int) env('LARAVEL_FLOW_STEP_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Compensation strategy
    |--------------------------------------------------------------------------
    |
    | How the engine walks compensators after a step failure.
    |
    | - 'reverse-order' (default): walk previously-completed steps from last
    |   to first; classic saga semantics.
    | - 'parallel': fan out compensators concurrently (v0.2 — currently
    |   unsupported, falls back to 'reverse-order' with a deprecation log).
    |
    */
    'compensation_strategy' => env('LARAVEL_FLOW_COMPENSATION', 'reverse-order'),

];
