<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Lifecycle\Actions\PruneAction;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * Every env var the config file reads, pinned by name: a renamed or misspelt variable would
 * silently fall back to its default in every host.
 */
$variables = [
    'key_type' => ['LIFECYCLE_KEY_TYPE', 'uuid', 'uuid'],
    'actor_key_type' => ['LIFECYCLE_ACTOR_KEY_TYPE', 'ulid', 'ulid'],
    'strict_writes' => ['LIFECYCLE_STRICT_WRITES', 'off', 'off'],
    'actor.from_auth' => ['LIFECYCLE_ACTOR_FROM_AUTH', 'no', 'no'],
    'actor.guard' => ['LIFECYCLE_ACTOR_GUARD', 'admin', 'admin'],
    'transactions.mysql_read_committed' => ['LIFECYCLE_MYSQL_READ_COMMITTED', 'false', false],
    'history.store_payload' => ['LIFECYCLE_HISTORY_STORE_PAYLOAD', 'off', 'off'],
    'history.purge_on_force_delete' => ['LIFECYCLE_HISTORY_PURGE_ON_FORCE_DELETE', 'off', 'off'],
    'history.prune_after_days' => ['LIFECYCLE_HISTORY_PRUNE_AFTER_DAYS', '90', '90'],
    'rollback.default_window' => ['LIFECYCLE_ROLLBACK_DEFAULT_WINDOW', '7 days', '7 days'],
    'schedules.queue.enabled' => ['LIFECYCLE_QUEUE_SWEEPS', 'on', 'on'],
    'schedules.queue.connection' => ['LIFECYCLE_QUEUE_CONNECTION', 'redis', 'redis'],
    'schedules.queue.name' => ['LIFECYCLE_QUEUE', 'lifecycle', 'lifecycle'],
    'schedules.prune_after_days' => ['LIFECYCLE_SCHEDULES_PRUNE_AFTER_DAYS', '14', '14'],
];

it('reads each setting from its documented env var', function (string $key, string $variable, string $value, mixed $expected): void {
    $_SERVER[$variable] = $_ENV[$variable] = $value;

    try {
        $config = require __DIR__.'/../../config/lifecycle.php';

        expect(data_get($config, $key))->toBe($expected);
    } finally {
        unset($_SERVER[$variable], $_ENV[$variable]);
    }
})->with(array_map(static fn (string $key, array $row): array => [$key, ...$row], array_keys($variables), $variables));

it('reads no env var beyond the documented ones', function () use ($variables): void {
    preg_match_all("/env\\('([A-Z_]+)'/", (string) file_get_contents(__DIR__.'/../../config/lifecycle.php'), $matches);

    expect($matches[1])->toHaveCount(count($variables))
        ->and($matches[1])->toEqualCanonicalizing(array_column($variables, 0));
});

it('reads an empty value of a nullable key as unset', function (string $key): void {
    config()->set($key, '');

    expect(PruneAction::days($key))->toBeNull();
})->with(['lifecycle.history.prune_after_days', 'lifecycle.schedules.prune_after_days']);

it('reads an empty rollback window as unlimited and keeps rolling back', function (): void {
    config()->set('lifecycle.rollback.default_window', '');
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));
    $document = Document::factory()->create();
    $document->transition('go');

    expect(Lifecycles::for($document)->canRollback()->allowed)->toBeTrue()
        ->and(Artisan::call('about', ['--only' => 'lifecycle']))->toBe(0);
});

it('prunes nothing when the retention keys are empty', function (): void {
    config()->set('lifecycle.history.prune_after_days', '');
    config()->set('lifecycle.schedules.prune_after_days', '');

    expect(Artisan::call('lifecycle:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to prune');
});
