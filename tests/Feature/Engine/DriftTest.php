<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleAdopted;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

/**
 * Writes without model events (query builder, saveQuietly, raw SQL) cannot be intercepted;
 * the next mutation adopts the stored state.
 */
it('adopts a query-builder write at the next mutation', function (): void {
    Event::fake([LifecycleAdopted::class, LifecycleTransitioned::class]);
    $listing = Listing::factory()->create();

    Listing::query()->whereKey($listing->id)->update(['status' => 'active']);

    $listing->refresh();
    $listing->transition('close');

    $kinds = LifecycleTransition::query()->orderBy('id')->pluck('kind')->map(fn (TransitionKind $k) => $k->value)->all();

    expect($kinds)->toBe(['initial', 'adopted', 'transition'])
        ->and(LifecycleState::query()->sole()->version)->toBe(3);

    Event::assertDispatched(LifecycleAdopted::class, fn (LifecycleAdopted $e): bool => $e->recordedState === 'draft' && $e->actualState === ListingStatus::Active);
    Event::assertDispatched(LifecycleTransitioned::class, fn (LifecycleTransitioned $e): bool => $e->kind === TransitionKind::Adopted && $e->system);
});

it('adopts a saveQuietly() write', function (): void {
    $listing = Listing::factory()->create();
    $listing->status = ListingStatus::Closed;
    $listing->saveQuietly();

    expect(Lifecycles::for($listing)->adopt())->toBeTrue()
        ->and(Lifecycles::for($listing)->adopt())->toBeFalse()
        ->and(LifecycleState::query()->sole()->state)->toBe('closed');
});

it('creates a missing record from the stored state', function (): void {
    $listing = Listing::factory()->create();
    LifecycleState::query()->delete();

    expect(Lifecycles::for($listing)->version())->toBe(0)
        ->and(Lifecycles::for($listing)->adopt())->toBeTrue();

    $adopted = LifecycleTransition::query()->latest('id')->first();

    expect($adopted?->kind)->toBe(TransitionKind::Adopted)
        ->and($adopted?->from_state)->toBeNull()
        ->and(LifecycleState::query()->sole()->version)->toBe(1);
});

it('decides dwell and seal in check() on the entry time apply() will adopt', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l);
        $l->state('a')->minDwell('1 hour');
        $l->state('b')->sealedAfter('1 day');
    });

    // No record yet (a row inserted without the model): apply() adopts it now, so the dwell starts now.
    $fresh = Document::query()->findOrFail(DB::table('documents')->insertGetId(['status' => 'a']));

    expect(Lifecycles::for($fresh)->check('go')->codes())->toBe(['min_dwell_not_reached'])
        ->and(Lifecycles::for($fresh)->attempt('go')->decision->codes())->toBe(['min_dwell_not_reached']);

    // A drifted record (state written behind the engine's back): the seal of the stored state
    // counts from the adoption, not from the record's old entry.
    $drifted = Document::factory()->create();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    Document::query()->whereKey($drifted->id)->update(['status' => 'b']);
    $drifted = Document::query()->findOrFail($drifted->id);

    expect(Lifecycles::for($drifted)->check('finish')->allowed)->toBeTrue()
        ->and(Lifecycles::for($drifted)->attempt('finish')->succeeded)->toBeTrue();

    Carbon::setTestNow();
});
