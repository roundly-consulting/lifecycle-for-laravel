<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleAdopted;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitionDenied;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('fires no after-commit event when the host transaction rolls back', function (): void {
    $transitioned = 0;
    Event::listen(LifecycleTransitioned::class, function () use (&$transitioned): void {
        $transitioned++;
    });
    $listing = Listing::factory()->create();

    DB::beginTransaction();
    $listing->transition('publish');
    DB::rollBack();

    expect($transitioned)->toBe(0);

    $listing->refresh();
    $listing->transition('publish');

    expect($transitioned)->toBe(1);
});

it('reports a denial even when the host rolls back because of it', function (): void {
    $denied = [];
    Event::listen(LifecycleTransitionDenied::class, function (LifecycleTransitionDenied $event) use (&$denied): void {
        $denied[] = $event;
    });
    $listing = Listing::factory()->create();

    DB::beginTransaction();

    try {
        $listing->transition('close');
    } catch (TransitionDeniedException) {
        DB::rollBack();
    }

    expect($denied)->toHaveCount(1)
        ->and($denied[0]->subjectType)->toBe($listing->getMorphClass())
        ->and($denied[0]->subjectId)->toBe($listing->id)
        ->and($denied[0]->lifecycle)->toBe('status')
        ->and($denied[0]->transition)->toBe('close')
        ->and($denied[0]->actor)->toBeNull()
        ->and($denied[0]->denials[0]->code)->toBe('not_from_current_state');
});

it('fires Transitioned for transitions and adoptions, never for creation', function (): void {
    Event::fake([LifecycleTransitioned::class, LifecycleAdopted::class, LifecycleTransitioning::class]);
    $listing = Listing::factory()->create();

    Event::assertNotDispatched(LifecycleTransitioned::class);

    $listing->transition('publish');
    Lifecycles::allowDirectWrites(fn () => $listing->update(['status' => ListingStatus::Closed]));

    Event::assertDispatchedTimes(LifecycleTransitioned::class, 2);
    Event::assertDispatched(LifecycleTransitioned::class, fn (LifecycleTransitioned $e): bool => $e->kind === TransitionKind::Transition);
    Event::assertDispatched(LifecycleTransitioned::class, fn (LifecycleTransitioned $e): bool => $e->kind === TransitionKind::Adopted && $e->from === ListingStatus::Active);
    Event::assertDispatchedTimes(LifecycleAdopted::class, 1);
    Event::assertDispatchedTimes(LifecycleTransitioning::class, 1);
});

it('fires nothing for a replay', function (): void {
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->idempotencyKey('k1')->apply('publish');

    Event::fake([LifecycleTransitioning::class, LifecycleTransitioned::class, LifecycleTransitionDenied::class, LifecycleAdopted::class]);
    Lifecycles::for($listing)->idempotencyKey('k1')->apply('publish');

    Event::assertNothingDispatched();
});
