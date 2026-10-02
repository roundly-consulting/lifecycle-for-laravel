<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\LifecycleServiceProvider;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;

$migrations = __DIR__.'/../database/migrations';

/**
 * Four files, two FK edges — both on lifecycle_transitions: `reverts_id` (self) and
 * `schedule_id` → lifecycle_schedules. The schedules' transition ids are plain indexed
 * columns, so the graph has no cycle.
 */
it('creates every foreign key target before the table that references it', function () use ($migrations): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 2);
});

it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(LifecycleServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(LifecycleServiceProvider::class)->toPublishMigrationsTimestamped('lifecycle-migrations', 4);
});

it('migrates forward only — no migration defines down()', function () use ($migrations): void {
    $files = glob($migrations.'/*.php') ?: [];

    expect($files)->toHaveCount(4);

    foreach ($files as $file) {
        expect((string) file_get_contents($file))->not->toContain('function down(');
    }
});

it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 4);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available')->group('pgsql');

it('rejects a child-before-parent order on postgres', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available')->group('pgsql');

it('applies its migrations on mysql', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('mysql', migrations: 4);
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available')->group('mysql');

it('rejects a child-before-parent order on mysql', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'mysql');
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available')->group('mysql');

/**
 * The driver-truth pin: a leg that quietly stayed on SQLite fails here instead of passing
 * as a "postgres" or "mysql" run.
 */
it('runs on the driver the environment declares', function (): void {
    expect(DatabaseDriver::current()->value)->toBe(DriverMatrix::driver());
});
