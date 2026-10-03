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
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\ExpiryChange;
use RoundlyConsulting\Lifecycle\Exceptions\ExpiryException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
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

    $listing->transition('publish');
    $scheduled = Lifecycles::for($listing)->asSystem()->schedule('expire', $at);
    Lifecycles::for($listing)->cancelScheduled('expire');
    Lifecycles::for($listing)->expireAt($at);
    Lifecycles::for($listing)->extend('1 day');
    Lifecycles::for($listing)->neverExpire();
    Lifecycles::sweep();
    Lifecycles::schedules()->warn();
    Lifecycles::schedules()->retry(5);

    expect($scheduled->dueAt)->toEqual($at)
        ->and($scheduled->forState->value)->toBe('active');

    $fake->assertScheduled($listing, 'expire');
    $fake->assertScheduled($listing, 'expire', $at);
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

it('renews, extends and expires under the fake like the real manager', function (): void {
    Lifecycles::fake();
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $handle = Lifecycles::for($listing);

    expect($handle->renew()->toIso8601String())->toBe('2026-11-01T10:00:00+00:00')
        ->and($handle->renew('2 days')->toIso8601String())->toBe('2026-10-04T10:00:00+00:00')
        ->and($handle->extend('1 day')->toIso8601String())->toBe('2026-10-03T10:00:00+00:00')
        ->and($handle->expireAt(CarbonImmutable::parse('2026-12-24 18:00', 'Europe/Bratislava'))->toIso8601String())->toBe('2026-12-24T17:00:00+00:00')
        ->and($handle->neverExpire())->toBeFalse();

    Lifecycles::assertExpiryChanged($listing, ExpiryChange::Renew);
});

it('extends a stored pending expiry under the fake', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    Lifecycles::fake();

    expect(Lifecycles::for($listing)->extend('1 day')->toIso8601String())->toBe('2026-11-02T10:00:00+00:00');
});

it('refuses expiry changes of a state without an expiry under the fake', function (): void {
    Lifecycles::fake();
    $listing = Listing::factory()->create();

    expect(fn () => Lifecycles::for($listing)->renew())->toThrow(ExpiryException::class, 'The state [draft] declares no expiry.')
        ->and(fn () => Lifecycles::for($listing)->neverExpire())->toThrow(ExpiryException::class);
});

it('asks for an interval when the state has no fixed TTL, under the fake', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, finish: fn (TransitionBuilder $finish) => $finish->allowSystem())
        ->state('b')->expiresAtAttribute('expires_at')->expiresVia('finish'));
    Lifecycles::fake();
    $document = Document::factory()->create();
    $document->transition('go');

    expect(fn () => Lifecycles::for($document)->renew())->toThrow(ExpiryException::class, 'pass the interval to renew()')
        ->and(Lifecycles::for($document)->renew('1 day')->toIso8601String())->toBe('2026-10-03T10:00:00+00:00');
});

it('refuses malformed expiry requests under the fake', function (): void {
    Lifecycles::fake();
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(fn () => Lifecycles::changeExpiry(new ExpiryChangeRequest($listing, 'status', ExpiryChange::Set)))
        ->toThrow(InvalidLifecycleUsageException::class, 'expireAt() needs an instant')
        ->and(fn () => Lifecycles::changeExpiry(new ExpiryChangeRequest($listing, 'status', ExpiryChange::Extend)))
        ->toThrow(InvalidLifecycleUsageException::class, 'extend() needs an interval');
});

it('asserts cancellations, warnings, retries, unfreezes and adoptions under the fake', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();
    $other = Listing::factory()->create();

    $fake->assertNothingCancelled();
    $fake->assertNotWarned();
    $fake->assertNothingRetried();
    $fake->assertNothingUnfrozen();
    $fake->assertNothingAdopted();

    expect(fn () => $fake->assertScheduleCancelled($listing))->toThrow(ExpectationFailedException::class, 'to be cancelled, but none was')
        ->and(fn () => $fake->assertWarned())->toThrow(ExpectationFailedException::class, 'Expected expiry warnings to be sent, but none were.')
        ->and(fn () => $fake->assertScheduleRetried())->toThrow(ExpectationFailedException::class, 'Expected a schedule to be retried, but none was.');

    $listing->transition('publish');
    Lifecycles::for($listing)->asSystem()->schedule('expire', CarbonImmutable::now()->addDay());
    $listing->lifecycle()->cancelScheduled('expire');
    Lifecycles::schedules()->warn();
    Lifecycles::schedules()->retry(7);
    Lifecycles::for($listing)->freeze();
    $listing->lifecycle()->unfreeze();
    $listing->lifecycle()->adopt();

    $fake->assertScheduleCancelled($listing);
    $fake->assertScheduleCancelled($listing, 'expire');
    $fake->assertWarned();
    $fake->assertWarned(1);
    $fake->assertScheduleRetried();
    $fake->assertScheduleRetried(7);
    $fake->assertUnfrozen($listing);
    $fake->assertAdopted($listing);
    Lifecycles::assertScheduleCancelled($listing, 'expire');

    expect(fn () => $fake->assertScheduleCancelled($listing, 'archive'))->toThrow(ExpectationFailedException::class, 'for [archive] to be cancelled')
        ->and(fn () => $fake->assertScheduleCancelled($other))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingCancelled())->toThrow(ExpectationFailedException::class, '1 cancellation(s)')
        ->and(fn () => $fake->assertWarned(2))->toThrow(ExpectationFailedException::class, 'Expected 2 expiry warning run(s), but 1 ran.')
        ->and(fn () => $fake->assertNotWarned())->toThrow(ExpectationFailedException::class, '1 warning run(s)')
        ->and(fn () => $fake->assertScheduleRetried(8))->toThrow(ExpectationFailedException::class, 'Expected schedule [8] to be retried, but it was not.')
        ->and(fn () => $fake->assertNothingRetried())->toThrow(ExpectationFailedException::class, '1 retry(s)')
        ->and(fn () => $fake->assertNothingUnfrozen())->toThrow(ExpectationFailedException::class, '1 unfreeze(s)')
        ->and(fn () => $fake->assertNothingAdopted())->toThrow(ExpectationFailedException::class, '1 were recorded');
});

it('records refused schedules under the fake', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(fn () => Lifecycles::for($listing)->schedule('nope', CarbonImmutable::now()->addDay()))
        ->toThrow(TransitionDeniedException::class);

    $fake->denyNext('expire');

    expect(fn () => Lifecycles::for($listing)->asSystem()->schedule('expire', CarbonImmutable::now()->addDay()))
        ->toThrow(TransitionDeniedException::class)
        ->and(Lifecycles::for($listing)->asSystem()->schedule('expire', CarbonImmutable::now()->addDay())->transition)->toBe('expire')
        ->and(array_map(static fn ($call) => $call->denied?->codes(), array_slice($fake->recorded(), 1)))->toBe([['unknown_transition'], ['guard_failed'], null]);

    $fake->assertScheduled($listing, 'expire');
});

it('does not count a refused schedule as scheduled', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();

    expect(fn () => Lifecycles::for($listing)->asSystem()->schedule('publish', CarbonImmutable::now()->addDay()))
        ->toThrow(TransitionDeniedException::class);

    $fake->assertNothingScheduled();

    expect(fn () => $fake->assertScheduled($listing, 'publish'))->toThrow(ExpectationFailedException::class, 'to be scheduled for [publish]');
});
