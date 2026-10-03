<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use RoundlyConsulting\Lifecycle\Support\SweepSchedule;

it('finds the sweep among the scheduled events', function (): void {
    expect(SweepSchedule::isScheduled())->toBeFalse();

    app(Schedule::class)->call(fn () => null)->hourly();
    app(Schedule::class)->command('lifecycle:prune')->daily();

    expect(SweepSchedule::isScheduled())->toBeFalse();

    app(Schedule::class)->command('lifecycle:sweep --queue')->everyMinute();

    expect(SweepSchedule::isScheduled())->toBeTrue();
});

it('does not know outside an application console', function (): void {
    $application = Container::getInstance();
    Container::setInstance(new Container);

    try {
        expect(SweepSchedule::isScheduled())->toBeNull();
    } finally {
        Container::setInstance($application);
    }
});

it('tells the sweep of each database connection apart', function (): void {
    app(Schedule::class)->command('lifecycle:sweep --database=secondary')->everyMinute();

    expect(SweepSchedule::isScheduled())->toBeFalse()
        ->and(SweepSchedule::isScheduled('secondary'))->toBeTrue()
        ->and(SweepSchedule::isScheduled('tenant'))->toBeFalse();

    app(Schedule::class)->command('lifecycle:sweep --database '.config('database.default'))->everyMinute();

    expect(SweepSchedule::isScheduled())->toBeTrue()
        ->and(SweepSchedule::isScheduled(config('database.default')))->toBeTrue();
});
