<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Support\Durations;

it('parses positive intervals', function (mixed $input, string $described): void {
    expect(Durations::describe(Durations::parse($input)))->toBe($described);
})->with([
    'days' => ['30 days', '30 days'],
    'weeks' => ['2 weeks', '14 days'],
    'mixed' => ['1 year 2 months', '1 year 2 months'],
    'iso' => ['P1M', '1 month'],
    'singular' => ['1 hour', '1 hour'],
    'seconds' => ['90 seconds', '90 seconds'],
    'date interval' => [new DateInterval('PT5M'), '5 minutes'],
    'carbon interval' => [CarbonInterval::days(3), '3 days'],
]);

it('refuses zero, negative, mixed and unparseable intervals', function (mixed $input): void {
    expect(Durations::tryParse($input))->toBeNull()
        ->and(fn () => Durations::parse($input))->toThrow(InvalidLifecycleUsageException::class);
})->with([
    'zero' => '0 days',
    'negative' => '-1 day',
    'inverted' => [CarbonInterval::days(1)->invert()],
    'mixed signs' => [CarbonInterval::create(0, 0, 0, 1, -2)],
    'garbage' => 'soon',
    'empty' => '',
    'int' => 5,
    'null' => null,
]);

it('parses signed offsets', function (): void {
    expect(Durations::describe(Durations::tryOffset('-1 day')))->toBe('-1 day')
        ->and(Durations::describe(Durations::tryOffset('2 hours')))->toBe('2 hours')
        ->and(Durations::describe(Durations::tryOffset('0 days')))->toBe('0 seconds')
        ->and(Durations::describe(Durations::tryOffset(CarbonInterval::days(1)->invert())))->toBe('-1 day')
        ->and(Durations::tryOffset('nope'))->toBeNull();
});

it('adds months without overflow and days as exact durations', function (): void {
    $jan31 = CarbonImmutable::parse('2026-01-31 10:00:00', 'UTC');
    $leap = CarbonImmutable::parse('2028-01-31 10:00:00', 'UTC');

    expect(Durations::add($jan31, Durations::parse('1 month'))->format('Y-m-d H:i:s'))->toBe('2026-02-28 10:00:00')
        ->and(Durations::add($leap, Durations::parse('1 month'))->format('Y-m-d'))->toBe('2028-02-29')
        ->and(Durations::add(CarbonImmutable::parse('2028-02-29', 'UTC'), Durations::parse('1 year'))->format('Y-m-d'))->toBe('2029-02-28')
        ->and(Durations::sub($jan31, Durations::parse('1 day'))->format('Y-m-d'))->toBe('2026-01-30')
        ->and(Durations::add($jan31, Durations::tryOffset('-2 hours'))->format('H:i'))->toBe('08:00');
});

it('keeps 30 days at 720 hours across a DST change', function (): void {
    $before = CarbonImmutable::parse('2026-10-10 12:00:00', 'Europe/Bratislava')->utc();
    $after = Durations::add($before, Durations::parse('30 days'));

    expect($after->getTimestamp() - $before->getTimestamp())->toBe(720 * 3600)
        ->and(Durations::seconds(Durations::parse('30 days'), $before))->toBe(720 * 3600);
});

it('reads intervals from config', function (): void {
    config()->set('test.window', '5 minutes');
    config()->set('test.none', null);
    config()->set('test.bad', 'later');

    expect(Durations::describe(Durations::fromConfig('test.window')))->toBe('5 minutes')
        ->and(Durations::nullableFromConfig('test.none'))->toBeNull()
        ->and(Durations::describe(Durations::nullableFromConfig('test.window')))->toBe('5 minutes')
        ->and(fn () => Durations::fromConfig('test.bad'))->toThrow(InvalidLifecycleConfigurationException::class, '[test.bad]')
        ->and(fn () => Durations::fromConfig('test.none'))->toThrow(InvalidLifecycleConfigurationException::class, 'null given');
});
