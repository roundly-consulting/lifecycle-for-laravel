<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ExpiryChangeRequest;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\ExpiryChange;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\ExpiryException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function at(string $instant): CarbonImmutable
{
    return CarbonImmutable::parse($instant, 'UTC');
}

function travelTo(string $instant): void
{
    Carbon::setTestNow(at($instant));
}

function expiryRow(): LifecycleSchedule
{
    return LifecycleSchedule::query()->where('pending_slot', '@expiry')->sole();
}

it('schedules the expiry of a TTL state on entry, with grace and the first warning', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $row = expiryRow();

    expect($row->kind)->toBe(ScheduleKind::Expiry)
        ->and($row->transition)->toBe('expire')
        ->and($row->for_state)->toBe('active')
        ->and($row->expires_at->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and($row->due_at->toDateTimeString())->toBe('2026-11-04 10:00:00')
        ->and($row->next_warn_at?->toDateTimeString())->toBe('2026-10-25 10:00:00')
        ->and($row->is_override)->toBeFalse()
        ->and(Lifecycles::for($listing)->expiresAt()?->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and(Lifecycles::for($listing)->scheduled()[0]->forState)->toBe(ListingStatus::Active);
});

it('reports expiry lazily before the sweep runs', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    travelTo('2026-11-01 09:59:59');
    expect(Lifecycles::for($listing)->isExpired())->toBeFalse()
        ->and(Lifecycles::for($listing)->isExpiringWithin('1 hour'))->toBeTrue()
        ->and(Lifecycles::for($listing)->effectiveState())->toBe(ListingStatus::Active);

    travelTo('2026-11-01 10:00:00');
    expect(Lifecycles::for($listing)->isExpired())->toBeTrue()
        ->and(Lifecycles::for($listing)->isInGrace())->toBeTrue()
        ->and(Lifecycles::for($listing)->isExpiringWithin('1 hour'))->toBeFalse()
        ->and(Lifecycles::for($listing)->state())->toBe(ListingStatus::Active)
        ->and(Lifecycles::for($listing)->effectiveState())->toBe(ListingStatus::Expired);

    travelTo('2026-11-04 10:00:00');
    expect(Lifecycles::for($listing)->isInGrace())->toBeFalse()
        ->and(Lifecycles::for($listing)->isExpired())->toBeTrue();
});

it('expires at the end of the grace period through the sweep', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    travelTo('2026-11-04 09:59:59');
    expect(Lifecycles::sweep()->executed)->toBe(0);

    travelTo('2026-11-04 10:00:00');
    $result = Lifecycles::sweep();

    expect($result->executed)->toBe(1)
        ->and($listing->fresh()?->status)->toBe(ListingStatus::Expired)
        ->and(expiryRowFinished()->outcome)->toBe(ScheduleOutcome::Executed)
        ->and($listing->lifecycleHistory()->latest('id')->first()?->kind->value)->toBe('expiry');
});

function expiryRowFinished(): LifecycleSchedule
{
    return LifecycleSchedule::query()->where('kind', 'expiry')->latest('id')->firstOrFail();
}

it('cancels the expiry when the state is left and schedules a fresh one on re-entry', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    travelTo('2026-10-10 10:00:00');
    $listing->transition('close');

    $cancelled = LifecycleSchedule::query()->sole();

    expect($cancelled->status)->toBe(ScheduleStatus::Cancelled)
        ->and($cancelled->outcome)->toBe(ScheduleOutcome::StateLeft)
        ->and($cancelled->pending_slot)->toBeNull()
        ->and($cancelled->cancelled_by_transition_id)->not->toBeNull()
        ->and(Lifecycles::for($listing)->expiresAt())->toBeNull();

    $listing->transition('reopen');

    expect(expiryRow()->expires_at->toDateTimeString())->toBe('2026-11-09 10:00:00');
});

it('keeps the expiry on a self-transition', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('touch')->from('b')->to('b')->allowSelf();
        $l->transition('lapse')->from('b')->to('c')->systemOnly();
        $l->state('b')->ttl('1 day')->expiresVia('lapse');
    });
    $document = Document::factory()->create();
    $document->transition('go');
    travelTo('2026-10-02 12:00:00');
    $document->transition('touch');

    expect(expiryRow()->expires_at->toDateTimeString())->toBe('2026-10-03 10:00:00');
});

it('sets, extends, renews and clears the expiry, restarting warnings each time', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $handle = Lifecycles::for($listing);

    expect($handle->expireAt(CarbonImmutable::parse('2026-10-20 12:00:00', 'Europe/Bratislava'))->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and(expiryRow()->is_override)->toBeTrue()
        ->and(expiryRow()->due_at->toDateTimeString())->toBe('2026-10-23 10:00:00')
        ->and($handle->extend('2 days')->toDateTimeString())->toBe('2026-10-22 10:00:00')
        ->and($handle->renew()->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and($handle->renew(CarbonInterval::hours(5))->toDateTimeString())->toBe('2026-10-02 15:00:00')
        ->and(LifecycleSchedule::query()->where('outcome', 'replaced')->count())->toBe(4)
        ->and(expiryRow()->warnings_sent)->toBe(0)
        ->and($handle->neverExpire())->toBeTrue()
        ->and($handle->expiresAt())->toBeNull()
        ->and($handle->neverExpire())->toBeFalse();

    expect(fn () => $handle->extend('1 day'))->toThrow(ExpiryException::class, 'no pending expiry');
});

it('refuses expiry changes the state cannot take', function (): void {
    $listing = Listing::factory()->create();

    expect(fn () => Lifecycles::for($listing)->renew())->toThrow(ExpiryException::class, 'declares no expiry');

    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
        $l->state('b')->expiresAtAttribute('expires_at')->expiresVia('lapse');
    });
    $document = Document::factory()->create();
    $document->transition('go');

    expect(fn () => Lifecycles::for($document)->renew())->toThrow(ExpiryException::class, 'no fixed TTL')
        ->and(fn () => Lifecycles::changeExpiry(new ExpiryChangeRequest($document, 'status', ExpiryChange::Set)))
        ->toThrow(InvalidLifecycleUsageException::class, 'needs an instant');
});

it('takes the expiry from an attribute and follows changes of it', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
        $l->state('b')->expiresAtAttribute('expires_at')->expiresVia('lapse');
    });
    $document = Document::factory()->create(['expires_at' => '2026-10-10 12:00:00']);
    $document->transition('go');

    expect(Lifecycles::for($document)->expiresAt()?->toDateTimeString())->toBe('2026-10-10 10:00:00');

    $document->update(['expires_at' => '2026-10-12 12:00:00']);
    expect(Lifecycles::for($document)->expiresAt()?->toDateTimeString())->toBe('2026-10-12 10:00:00');

    $document->update(['expires_at' => null]);
    expect(Lifecycles::for($document)->expiresAt())->toBeNull();

    $document->update(['expires_at' => '2026-10-15 12:00:00']);
    Lifecycles::for($document)->extend('1 day');
    $document->update(['expires_at' => '2026-12-01 12:00:00']);

    expect(Lifecycles::for($document)->expiresAt()?->toDateTimeString())->toBe('2026-10-16 10:00:00');
});

it('takes the expiry from a TTL closure', function (mixed $value, ?string $expected): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($value): void {
        baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
        $l->state('b')->ttl(fn (Document $document) => $value)->expiresVia('lapse');
    });
    $document = Document::factory()->create();
    $document->transition('go');

    expect(Lifecycles::for($document)->expiresAt()?->toDateTimeString())->toBe($expected);
})->with([
    'interval' => [CarbonInterval::days(2), '2026-10-04 10:00:00'],
    'instant' => [CarbonImmutable::parse('2026-10-05 12:00:00', 'Europe/Bratislava'), '2026-10-05 10:00:00'],
    'never' => [null, null],
]);

it('refuses a TTL closure returning anything else', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
        $l->state('b')->ttl(fn () => 'tomorrow')->expiresVia('lapse');
    });

    Document::factory()->create()->transition('go');
})->throws(InvalidLifecycleUsageException::class, 'A TTL closure must return');

it('gives a forward re-activation of an expired subject a fresh TTL', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    travelTo('2026-11-04 10:00:00');
    Lifecycles::sweep();

    travelTo('2026-11-05 10:00:00');
    $listing->transition('reactivate');

    expect(expiryRow()->expires_at->toDateTimeString())->toBe('2026-12-05 10:00:00');
});

it('scopes expired, expiring and in-grace subjects', function (): void {
    [$soon, $later, $none] = Listing::factory()->count(3)->create()->all();
    $soon->transition('publish');
    travelTo('2026-10-10 10:00:00');
    $later->transition('publish');

    travelTo('2026-11-02 10:00:00');

    expect(Listing::query()->whereExpired()->pluck('id')->all())->toBe([$soon->id])
        ->and(Listing::query()->whereInGrace()->pluck('id')->all())->toBe([$soon->id])
        ->and(Listing::query()->whereNotExpired()->orderBy('id')->pluck('id')->all())->toBe([$later->id, $none->id])
        ->and(Listing::query()->whereExpiringWithin('8 days')->pluck('id')->all())->toBe([$later->id])
        ->and(Listing::query()->whereExpiringWithin('1 day')->pluck('id')->all())->toBe([]);
});

it('reads expiry from eager-loaded schedules', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $loaded = Listing::query()->withLifecycle()->findOrFail($listing->id);

    expect($loaded->relationLoaded('lifecycleSchedules'))->toBeTrue()
        ->and(Lifecycles::for($loaded)->expiresAt()?->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and(Lifecycles::for($loaded)->version())->toBe(2);
});
