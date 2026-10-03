<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;

return [

    /*
    |--------------------------------------------------------------------------
    | Key types
    |--------------------------------------------------------------------------
    |
    | The id type of the polymorphic columns the migrations create: "bigint",
    | "uuid" or "ulid". `key_type` is used for the subjects (your models with a
    | lifecycle), `actor_key_type` for actors (who performed a transition) —
    | subjects and users often differ. Set both before running the migrations.
    |
    */

    'key_type' => env('LIFECYCLE_KEY_TYPE', 'bigint'),

    'actor_key_type' => env('LIFECYCLE_ACTOR_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap a package model for a subclass of your own. The replacement must
    | extend the package model.
    |
    */

    'models' => [
        'state' => LifecycleState::class,
        'transition' => LifecycleTransition::class,
        'schedule' => LifecycleSchedule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Subjects
    |--------------------------------------------------------------------------
    |
    | Your models with a lifecycle (classes implementing LifecycleSubject).
    | `php artisan lifecycle:validate` checks every lifecycle of every model
    | listed here, including the columns its definition names. Models work
    | without being listed; list them so CI validates something.
    |
    */

    'subjects' => [],

    /*
    |--------------------------------------------------------------------------
    | Strict state writes
    |--------------------------------------------------------------------------
    |
    | When on, saving a model whose lifecycle attribute was changed directly
    | (`$order->status = 'paid'; $order->save()`) throws — state changes go
    | through transitions. Wrap deliberate writes in
    | `Lifecycles::allowDirectWrites(fn () => ...)`.
    |
    */

    'strict_writes' => env('LIFECYCLE_STRICT_WRITES', true),

    /*
    |--------------------------------------------------------------------------
    | Actor
    |--------------------------------------------------------------------------
    |
    | When a call names no actor (`->by($user)`), use the authenticated user of
    | the given guard (null = the default guard).
    |
    */

    'actor' => [
        'from_auth' => env('LIFECYCLE_ACTOR_FROM_AUTH', true),
        'guard' => env('LIFECYCLE_ACTOR_GUARD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactions
    |--------------------------------------------------------------------------
    |
    | `attempts`: how often a transaction is retried after a deadlock (1..10);
    | retries only happen when the package opened the outermost transaction.
    | `mysql_read_committed`: on MySQL/MariaDB, a transaction that counts a
    | quota runs at READ COMMITTED (it needs row-based or mixed binary logging).
    | Off: it keeps your isolation and the quota count takes locking reads
    | (never over the quota; bursts into one partition may deadlock and retry).
    |
    */

    'transactions' => [
        'attempts' => 3,
        'mysql_read_committed' => env('LIFECYCLE_MYSQL_READ_COMMITTED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | `store_payload` keeps the validated payload (minus `sensitive()` keys) in
    | the history row; `max_context_bytes` caps its JSON size (1024..1048576);
    | `reason_max_length` caps reasons (1..10000); `purge_on_force_delete`
    | deletes a force-deleted subject's records, history and schedules;
    | `prune_after_days` is the `lifecycle:prune` default for history rows
    | (1..36500, null = never).
    |
    */

    'history' => [
        'store_payload' => env('LIFECYCLE_HISTORY_STORE_PAYLOAD', true),
        'max_context_bytes' => 16384,
        'reason_max_length' => 1000,
        'purge_on_force_delete' => env('LIFECYCLE_HISTORY_PURGE_ON_FORCE_DELETE', true),
        'prune_after_days' => env('LIFECYCLE_HISTORY_PRUNE_AFTER_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rollbacks
    |--------------------------------------------------------------------------
    |
    | `default_window` is how long a transition stays reversible when it
    | declares no window of its own (an interval such as "7 days"; null =
    | unlimited). `max_steps` bounds how many rows one rollbackTo() reverts
    | (1..1000).
    |
    */

    'rollback' => [
        'default_window' => env('LIFECYCLE_ROLLBACK_DEFAULT_WINDOW'),
        'max_steps' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedules & expiry
    |--------------------------------------------------------------------------
    |
    | `lifecycle:sweep` runs due expiries and scheduled transitions in keyset
    | batches of `batch_size` (1..10000), at most `max_per_run` (1..1000000) per
    | run. A scheduled transition refused only for retryable reasons is retried
    | after `retry_after` until `max_attempts` (1..100). With `queue.enabled`
    | the sweep dispatches one job per schedule instead of running it inline.
    | `prune_after_days` is the `lifecycle:prune` default for finished schedule
    | rows (null = never).
    |
    */

    'schedules' => [
        'batch_size' => 500,
        'max_per_run' => 10000,
        'max_attempts' => 5,
        'retry_after' => '5 minutes',
        'queue' => [
            'enabled' => env('LIFECYCLE_QUEUE_SWEEPS', false),
            'connection' => env('LIFECYCLE_QUEUE_CONNECTION'),
            'name' => env('LIFECYCLE_QUEUE'),
        ],
        'prune_after_days' => env('LIFECYCLE_SCHEDULES_PRUNE_AFTER_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Key prefix for the rate limiter buckets of `rateLimit()` transitions.
    |
    */

    'rate_limits' => [
        'prefix' => 'lifecycle',
    ],

    /*
    |--------------------------------------------------------------------------
    | Graph export
    |--------------------------------------------------------------------------
    |
    | The format `lifecycle:graph` and the graph() helpers use when none is
    | given: "mermaid" (a state diagram) or "dot" (Graphviz).
    |
    */

    'graph' => [
        'default_format' => 'mermaid',
    ],

];
