<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Lifecycle\Accessors\SchedulesAccessor;
use RoundlyConsulting\Lifecycle\DataTransferObjects\CancelScheduleRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ExpiryChangeRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduleRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Lifecycle\Enums\ExpiryChange;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('runs the schedule and expiry methods through the facade', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $at = CarbonImmutable::parse('2026-10-03', 'UTC');

    expect(Lifecycles::schedules())->toBeInstanceOf(SchedulesAccessor::class)
        ->and(Lifecycles::schedule(new ScheduleRequest($listing, 'status', 'expire', $at, system: true))->transition)->toBe('expire')
        ->and(Lifecycles::cancelScheduled(new CancelScheduleRequest($listing, 'status', 'expire')))->toBeTrue()
        ->and(Lifecycles::changeExpiry(new ExpiryChangeRequest($listing, 'status', ExpiryChange::Set, $at))?->toDateTimeString())->toBe('2026-10-03 00:00:00')
        ->and(Lifecycles::runDueSchedules(new SweepOptions)->total())->toBe(0)
        ->and(Lifecycles::sendExpiryWarnings(new SweepOptions))->toBe(0)
        ->and(Lifecycles::retrySchedule(123))->toBeFalse()
        ->and(Lifecycles::sweep()->warned)->toBe(0);
});

it('records schedules, expiry changes and sweeps under the fake', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();
    $at = CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC');

    $fake->assertNothingScheduled();
    $fake->assertNoExpiryChanged();
    $fake->assertNotSwept();

    $scheduled = Lifecycles::for($listing)->schedule('publish', $at);
    Lifecycles::for($listing)->cancelScheduled('publish');
    Lifecycles::for($listing)->expireAt($at);
    Lifecycles::for($listing)->extend('1 day');
    Lifecycles::for($listing)->neverExpire();
    Lifecycles::sweep();
    Lifecycles::schedules()->warn();
    Lifecycles::schedules()->retry(5);

    expect($scheduled->dueAt)->toEqual($at)
        ->and($scheduled->forState->value)->toBe('draft');

    $fake->assertScheduled($listing, 'publish');
    $fake->assertScheduled($listing, 'publish', $at);
    $fake->assertExpiryChanged($listing);
    $fake->assertExpiryChanged($listing, ExpiryChange::Extend);
    $fake->assertSwept();
    $fake->assertSwept(1);

    expect(fn () => $fake->assertScheduled($listing, 'archive'))->toThrow(ExpectationFailedException::class, 'to be scheduled for [archive]')
        ->and(fn () => $fake->assertNothingScheduled())->toThrow(ExpectationFailedException::class, '1 schedule(s)')
        ->and(fn () => $fake->assertExpiryChanged($listing, ExpiryChange::Renew))->toThrow(ExpectationFailedException::class, 'expiry of')
        ->and(fn () => $fake->assertNoExpiryChanged())->toThrow(ExpectationFailedException::class, 'were recorded')
        ->and(fn () => $fake->assertSwept(2))->toThrow(ExpectationFailedException::class, 'Expected 2 lifecycle sweep(s), but 1 ran.')
        ->and(fn () => $fake->assertNotSwept())->toThrow(ExpectationFailedException::class, 'Expected no lifecycle sweep');
});

it('fails assertSwept when nothing swept', function (): void {
    Lifecycles::fake()->assertSwept();
})->throws(ExpectationFailedException::class, 'Expected a lifecycle sweep, but none ran.');
