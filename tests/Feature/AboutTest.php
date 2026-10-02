<?php

declare(strict_types=1);

/**
 * The `php artisan about` section, pinned with testing-for-laravel's render check: it proves
 * the capture is not empty before anything else is trusted. The rows report presence and
 * flags only, never a payload or a secret.
 */
it('renders its about section', function (): void {
    expect('lifecycle')->toLeakNoSecrets([], mustRender: ['Graph format', 'mermaid']);
});

it('reports the configured graph format', function (): void {
    config()->set('lifecycle.graph.default_format', 'dot');

    expect('lifecycle')->toLeakNoSecrets([], mustRender: ['Graph format', 'dot']);
});
