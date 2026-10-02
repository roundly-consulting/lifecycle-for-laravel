<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Ticket;

afterEach(fn () => Carbon::setTestNow());

it('starts the adopted stay now, not at updated_at', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'));
    $listing = Listing::factory()->create();
    Listing::query()->whereKey($listing->id)->update(['status' => 'closed']);
    LifecycleState::query()->delete();

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    Lifecycles::for($listing)->adopt();

    expect(LifecycleState::query()->sole()->entered_at->toDateTimeString())->toBe('2026-10-02 10:00:00');
});

it('initialises a NULL stored state', function (): void {
    Ticket::query()->insert(['status' => null]);
    $ticket = Ticket::query()->sole();

    expect(Lifecycles::for($ticket)->adopt())->toBeTrue()
        ->and($ticket->status)->toBe('new')
        ->and($ticket->fresh()?->status)->toBe('new')
        ->and(LifecycleTransition::query()->sole()->kind)->toBe(TransitionKind::Initial);
});

it('initialises a NULL stored state before a transition', function (): void {
    Ticket::query()->insert(['status' => null]);
    $ticket = Ticket::query()->sole();

    expect($ticket->transition('open')->from)->toBe('new');
});

it('adopts every row of a model in chunks, skipping trashed rows', function (): void {
    Listing::factory()->count(3)->create();
    $trashed = Listing::factory()->create();
    $trashed->delete();
    Listing::query()->update(['status' => 'closed']);
    LifecycleState::query()->delete();
    Ticket::query()->insert(['status' => null]);

    expect(Lifecycles::model(Listing::class)->adopt(chunk: 2))->toBe(3)
        ->and(Lifecycles::adoptAll(Listing::class))->toBe(0)
        ->and(Lifecycles::adoptAll(Ticket::class, 'status', 10, false))->toBe(1)
        ->and(LifecycleState::query()->where('state', 'closed')->count())->toBe(3);
});

it('refuses a chunk size below one', function (): void {
    Lifecycles::adoptAll(Listing::class, chunk: 0);
})->throws(InvalidLifecycleUsageException::class, 'chunk size');

it('adopts a direct write made inside allowDirectWrites immediately', function (): void {
    $listing = Listing::factory()->create();

    Lifecycles::allowDirectWrites(fn () => $listing->update(['status' => ListingStatus::Expired]));

    expect(LifecycleState::query()->sole()->state)->toBe('expired');
});
