<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Support\Isolation;

it('marks a connection as reading committed for the callback only', function (): void {
    $isolation = new Isolation;
    $inside = null;

    $result = $isolation->run('mysql', function () use ($isolation, &$inside): string {
        $inside = $isolation->readsCommitted('mysql');

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($inside)->toBeTrue()
        ->and($isolation->readsCommitted('mysql'))->toBeFalse();
});

it('counts nested runs and keeps connections apart', function (): void {
    $isolation = new Isolation;
    $seen = [];

    $isolation->run('mysql', function () use ($isolation, &$seen): void {
        $isolation->run('mysql', function () use ($isolation, &$seen): void {
            $seen[] = [$isolation->readsCommitted('mysql'), $isolation->readsCommitted('other')];
        });

        $seen[] = [$isolation->readsCommitted('mysql'), $isolation->readsCommitted('other')];
    });

    expect($seen)->toBe([[true, false], [true, false]])
        ->and($isolation->readsCommitted('mysql'))->toBeFalse();
});

it('resets the mark after a throwing callback', function (): void {
    $isolation = new Isolation;

    expect(fn () => $isolation->run('mysql', fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom')
        ->and($isolation->readsCommitted('mysql'))->toBeFalse();
});

it('is bound per request or job', function (): void {
    $first = app(Isolation::class);

    expect(app(Isolation::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(Isolation::class))->not->toBe($first);
});
