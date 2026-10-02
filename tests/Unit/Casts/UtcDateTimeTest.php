<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Casts\UtcDateTime;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;

it('reads UTC strings as UTC instants', function (): void {
    $value = (new UtcDateTime)->get(new LifecycleState, 'entered_at', '2026-10-02 08:00:00', []);

    expect($value?->tzName)->toBe('UTC')
        ->and($value?->toDateTimeString())->toBe('2026-10-02 08:00:00')
        ->and((new UtcDateTime)->get(new LifecycleState, 'entered_at', '2026-10-02 08:00:00.000000', [])?->second)->toBe(0)
        ->and((new UtcDateTime)->get(new LifecycleState, 'entered_at', null, []))->toBeNull();
});

it('writes any instant as a UTC string and takes strings as UTC', function (): void {
    $cast = new UtcDateTime;

    expect($cast->set(new LifecycleState, 'entered_at', CarbonImmutable::parse('2026-10-02 10:00:00', 'Europe/Bratislava'), []))->toBe('2026-10-02 08:00:00')
        ->and($cast->set(new LifecycleState, 'entered_at', '2026-10-02 10:00:00', []))->toBe('2026-10-02 10:00:00')
        ->and($cast->set(new LifecycleState, 'entered_at', null, []))->toBeNull();
});

it('refuses anything else', function (): void {
    expect(fn () => (new UtcDateTime)->set(new LifecycleState, 'entered_at', 'tomorrow', []))
        ->toThrow(InvalidLifecycleUsageException::class)
        ->and(fn () => (new UtcDateTime)->get(new LifecycleState, 'entered_at', 'garbage', []))
        ->toThrow(InvalidLifecycleUsageException::class);
});
