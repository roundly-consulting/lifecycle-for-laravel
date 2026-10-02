<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleExpired;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('fires Expired and Transitioned of kind expiry when an expiry runs', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    Event::fake([LifecycleExpired::class, LifecycleTransitioned::class]);
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    Carbon::setTestNow(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC'));
    Lifecycles::sweep();

    Event::assertDispatched(LifecycleExpired::class, fn (LifecycleExpired $e): bool => $e->from === ListingStatus::Active
        && $e->to === ListingStatus::Expired && $e->expiresAt->toDateTimeString() === '2026-11-01 10:00:00');
    Event::assertDispatched(LifecycleTransitioned::class, fn (LifecycleTransitioned $e): bool => $e->kind === TransitionKind::Expiry && $e->system);
    Carbon::setTestNow();
});
