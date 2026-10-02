<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\Identifiers;

it('returns now in UTC, truncated to the second, honouring the test clock', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:15:30.987654', 'Europe/Bratislava'));

    $now = Clock::now();

    expect($now->tzName)->toBe('UTC')
        ->and($now->format('Y-m-d H:i:s.u'))->toBe('2026-10-02 08:15:30.000000');

    Carbon::setTestNow();
});

it('keeps a test instant inside a DST fall-back hour exact', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-25 00:30:00', 'UTC'));

    expect(Clock::now()->toIso8601String())->toBe('2026-10-25T00:30:00+00:00');

    Carbon::setTestNow(fn () => CarbonImmutable::parse('2026-10-25 00:30:00', 'UTC'));

    expect(Clock::now()->tzName)->toBe('UTC');

    Carbon::setTestNow();
});

it('formats any instant as a UTC storage string', function (): void {
    $local = CarbonImmutable::parse('2026-07-01 12:00:00', 'America/New_York');

    expect(Clock::format($local))->toBe('2026-07-01 16:00:00')
        ->and(Clock::utc($local)->tzName)->toBe('UTC');
});

it('validates identifiers', function (): void {
    expect(Identifiers::isTransitionName('publish'))->toBeTrue()
        ->and(Identifiers::isTransitionName('order.v2:pay-now_1'))->toBeTrue()
        ->and(Identifiers::isTransitionName('-x'))->toBeFalse()
        ->and(Identifiers::isTransitionName('a b'))->toBeFalse()
        ->and(Identifiers::isTransitionName(str_repeat('a', 65)))->toBeFalse()
        ->and(Identifiers::isColumn('user_id'))->toBeTrue()
        ->and(Identifiers::isColumn('1x'))->toBeFalse()
        ->and(Identifiers::isColumn('a;b'))->toBeFalse()
        ->and(Identifiers::isStateKey(''))->toBeFalse()
        ->and(Identifiers::isStateKey(str_repeat('é', 64)))->toBeTrue()
        ->and(Identifiers::isStateKey(str_repeat('é', 65)))->toBeFalse();
});
