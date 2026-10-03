<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Support\WriteGuard;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

it('scopes the write guard to one request or job', function (): void {
    $first = app(WriteGuard::class);

    expect(app(WriteGuard::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(WriteGuard::class))->not->toBe($first);
});

it('releases the allowance even when the callback throws', function (): void {
    $guard = app(WriteGuard::class);

    rescue(fn () => $guard->allowDirectWrites(fn () => throw new RuntimeException), report: false);
    rescue(fn () => $guard->engine(new Document, 'status', fn () => throw new RuntimeException), report: false);

    expect($guard->allowsDirectWrites())->toBeFalse()
        ->and($guard->inEngine(new Document, 'status'))->toBeFalse()
        ->and($guard->allowDirectWrites(fn (): bool => $guard->allowsDirectWrites()))->toBeTrue();
});

it('marks only the subject row and lifecycle the engine is writing', function (): void {
    $guard = app(WriteGuard::class);
    $subject = Document::factory()->create();
    $other = Document::factory()->create();

    $inside = $guard->engine($subject, 'status', fn (): array => [
        $guard->inEngine($subject, 'status'),
        $guard->inEngine(Document::query()->findOrFail($subject->id), 'status'),
        $guard->inEngine($subject, 'other'),
        $guard->inEngine($other, 'status'),
        $guard->engine($subject, 'status', fn (): bool => $guard->inEngine($subject, 'status')),
        $guard->inEngine($subject, 'status'),
    ]);

    expect($inside)->toBe([true, true, false, false, true, true])
        ->and($guard->inEngine($subject, 'status'))->toBeFalse();
});
