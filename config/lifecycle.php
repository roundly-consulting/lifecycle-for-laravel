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
    | Registered definitions
    |--------------------------------------------------------------------------
    |
    | Definition classes `lifecycle:validate` and `lifecycle:graph` work on when
    | none is named. Definitions are used without being listed here.
    |
    */

    'definitions' => [],

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
        'guard' => env('LIFECYCLE_AUTH_GUARD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactions
    |--------------------------------------------------------------------------
    |
    | How often a transition is retried after a deadlock (1..10). Retries only
    | happen when the package opened the outermost transaction.
    |
    */

    'transaction_attempts' => 3,

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | `store_payload` keeps the validated payload (minus `sensitive()` keys) in
    | the history row; `max_context_bytes` caps its JSON size (1024..1048576);
    | `reason_max_length` caps reasons (1..10000); `purge_on_force_delete`
    | deletes a force-deleted subject's records, history and schedules.
    |
    */

    'history' => [
        'store_payload' => env('LIFECYCLE_STORE_PAYLOAD', true),
        'max_context_bytes' => 16384,
        'reason_max_length' => 1000,
        'purge_on_force_delete' => env('LIFECYCLE_PURGE_ON_FORCE_DELETE', true),
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
