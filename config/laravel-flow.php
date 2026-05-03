<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    |
    | Persistence remains opt-in: the in-memory engine path still works with no
    | database writes. When enabled, synchronous engine runs are written to the
    | configured connection and common secret-looking payload keys are redacted
    | before JSON payloads are stored.
    |
    */
    'default_storage' => env('LARAVEL_FLOW_STORAGE', null),

    'persistence' => [
        'enabled' => env('LARAVEL_FLOW_PERSISTENCE_ENABLED', false),

        'redaction' => [
            'enabled' => env('LARAVEL_FLOW_REDACTION_ENABLED', true),
            'replacement' => env('LARAVEL_FLOW_REDACTION_REPLACEMENT', '[redacted]'),
            'keys' => [
                'api_key',
                'authorization',
                'password',
                'secret',
                'token',
            ],
        ],

        'retention' => [
            'days' => env('LARAVEL_FLOW_RETENTION_DAYS', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | When true, every flow run + step transition emits a Laravel event the
    | host application can subscribe to. With persistence enabled, the same
    | transition audit is also written to the `flow_audit` table.
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
    |   unsupported, silently falls back to 'reverse-order'). Setting this
    |   value before v0.2 is harmless but does not change behaviour.
    |
    */
    'compensation_strategy' => env('LARAVEL_FLOW_COMPENSATION', 'reverse-order'),

];
