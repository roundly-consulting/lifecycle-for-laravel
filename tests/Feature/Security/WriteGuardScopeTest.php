<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Support\WriteGuard;

it('scopes the write guard to one request or job', function (): void {
    $first = app(WriteGuard::class);

    expect(app(WriteGuard::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(WriteGuard::class))->not->toBe($first);
});

it('releases the allowance even when the callback throws', function (): void {
    $guard = app(WriteGuard::class);

    rescue(fn () => $guard->allowDirectWrites(fn () => throw new RuntimeException), report: false);
    rescue(fn () => $guard->engine(fn () => throw new RuntimeException), report: false);

    expect($guard->allowsDirectWrites())->toBeFalse()
        ->and($guard->inEngine())->toBeFalse()
        ->and($guard->allowDirectWrites(fn (): bool => $guard->allowsDirectWrites()))->toBeTrue();
});
