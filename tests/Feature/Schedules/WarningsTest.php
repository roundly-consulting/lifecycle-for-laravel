<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Engine\Warnings;
use RoundlyConsulting\Lifecycle\Events\LifecycleExpiring;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    Event::fake([LifecycleExpiring::class]);
});
afterEach(fn () => Carbon::setTestNow());

function warnAt(string $instant): int
{
    Carbon::setTestNow(CarbonImmutable::parse($instant, 'UTC'));

    return Lifecycles::schedules()->warn();
}

it('fires each lead once, at its instant', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(warnAt('2026-10-25 09:59:59'))->toBe(0)
        ->and(warnAt('2026-10-25 10:00:00'))->toBe(1)
        ->and(warnAt('2026-10-26 10:00:00'))->toBe(0)
        ->and(warnAt('2026-10-31 10:00:00'))->toBe(1)
        ->and(warnAt('2026-10-31 11:00:00'))->toBe(0)
        ->and(LifecycleSchedule::query()->sole()->warnings_sent)->toBe(2)
        ->and(LifecycleSchedule::query()->sole()->next_warn_at)->toBeNull();

    Event::assertDispatchedTimes(LifecycleExpiring::class, 2);
    Event::assertDispatched(LifecycleExpiring::class, fn (LifecycleExpiring $e): bool => $e->lead->d === 7
        && $e->state === ListingStatus::Active && $e->expiresAt->toDateTimeString() === '2026-11-01 10:00:00');
});

it('collapses leads that already passed into one warning with the most imminent', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(warnAt('2026-10-31 12:00:00'))->toBe(1);

    Event::assertDispatchedTimes(LifecycleExpiring::class, 1);
    Event::assertDispatched(LifecycleExpiring::class, fn (LifecycleExpiring $e): bool => $e->lead->d === 1);
});

it('fires nothing once the expiry has passed', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(warnAt('2026-11-02 10:00:00'))->toBe(0)
        ->and(LifecycleSchedule::query()->sole()->next_warn_at)->toBeNull();

    Event::assertNotDispatched(LifecycleExpiring::class);
});

it('warns now for a passed lead and later for one still ahead', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    Lifecycles::for($listing)->expireAt(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

    expect(warnAt('2026-10-02 10:00:00'))->toBe(1)
        ->and(LifecycleSchedule::query()->where('status', 'pending')->sole()->next_warn_at?->toDateTimeString())->toBe('2026-10-04 10:00:00')
        ->and(warnAt('2026-10-04 10:00:00'))->toBe(1);
});

it('fires once when two warners race (compare-and-swap on the sent count)', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $stale = LifecycleSchedule::query()->sole();

    expect(warnAt('2026-10-25 10:00:00'))->toBe(1);

    // A second warner holding the pre-warning row loses the CAS.
    $affected = $stale->newQuery()->whereKey($stale->id)->where('warnings_sent', $stale->warnings_sent)->toBase()->update(['warnings_sent' => 1]);

    expect($affected)->toBe(0);
});

it('advances through leads deterministically', function (): void {
    $leads = [Durations::parse('7 days'), Durations::parse('1 day')];
    $expires = CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC');

    $step = Warnings::advance($leads, $expires, CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'), 0);

    expect($step->fire)->toBeNull()
        ->and($step->sent)->toBe(0)
        ->and($step->next?->toDateTimeString())->toBe('2026-10-25 10:00:00')
        ->and(Warnings::first(null, $leads))->toBeNull()
        ->and(Warnings::first($expires, []))->toBeNull();
});

it('skips warnings of a schedule whose subject is gone', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    Listing::query()->whereKey($listing->id)->forceDelete();

    expect(warnAt('2026-10-25 10:00:00'))->toBe(0)
        ->and(LifecycleSchedule::query()->sole()->next_warn_at)->toBeNull();
});
