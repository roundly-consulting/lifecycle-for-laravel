<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;

/**
 * The `php artisan about` section, pinned with testing-for-laravel's render check: it proves
 * the capture is not empty before anything else is trusted. The rows report presence and
 * flags only, never a payload or a secret.
 */
it('renders its about section', function (): void {
    expect('lifecycle')->toLeakNoSecrets([], mustRender: [
        'Graph format', 'mermaid',
        'Key type', 'bigint',
        'Actor key type',
        'Strict state writes', 'ON',
        'Actor from auth',
        'Registered subjects', '0',
        'State model', 'LifecycleState',
        'History model', 'LifecycleTransition',
        'Schedule model', 'LifecycleSchedule',
        'Schedule batch size', '500',
        'Queued sweeps', 'OFF',
        'MySQL quota isolation', 'READ COMMITTED',
        'History pruning', 'OFF',
    ]);
});

it('reports the configured graph format', function (): void {
    config()->set('lifecycle.graph.default_format', 'dot');

    expect('lifecycle')->toLeakNoSecrets([], mustRender: ['Graph format', 'dot']);
});

it('reports the history retention', function (): void {
    config()->set('lifecycle.history.prune_after_days', '90');

    expect('lifecycle')->toLeakNoSecrets([], mustRender: ['History pruning', '90 days']);
});

it('reports the MySQL quota isolation', function (): void {
    config()->set('lifecycle.transactions.mysql_read_committed', false);

    expect('lifecycle')->toLeakNoSecrets([], mustRender: ['MySQL quota isolation', 'locking reads']);
});

it('counts the configured subjects', function (): void {
    config()->set('lifecycle.subjects', [Listing::class, Order::class]);

    expect('lifecycle')->toLeakNoSecrets([], mustRender: ['Registered subjects', '2']);
});
