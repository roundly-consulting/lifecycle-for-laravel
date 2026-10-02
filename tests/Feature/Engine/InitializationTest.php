<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Ticket;

it('assigns the initial state and records it on creation', function (): void {
    $listing = Listing::factory()->create();
    $row = LifecycleTransition::query()->sole();
    $record = LifecycleState::query()->sole();

    expect($listing->status)->toBe(ListingStatus::Draft)
        ->and($row->kind)->toBe(TransitionKind::Initial)
        ->and($row->from_state)->toBeNull()
        ->and($row->to_state)->toBe('draft')
        ->and($row->version)->toBe(1)
        ->and($row->is_system)->toBeTrue()
        ->and($record->state)->toBe('draft')
        ->and($record->version)->toBe(1)
        ->and(Lifecycles::for($listing)->version())->toBe(1);
});

it('accepts a declared non-initial state from a factory and records it', function (): void {
    $listing = Listing::factory()->create(['status' => ListingStatus::Closed]);

    expect($listing->status)->toBe(ListingStatus::Closed)
        ->and(LifecycleTransition::query()->sole()->to_state)->toBe('closed')
        ->and(LifecycleState::query()->sole()->state)->toBe('closed');
});

it('refuses an undeclared state on creation', function (): void {
    Listing::query()->create(['status' => 'nope']);
})->throws(ValueError::class);

it('refuses an undeclared raw state on a model without an enum cast', function (): void {
    Ticket::query()->create(['status' => 'nope']);
})->throws(UnknownStateException::class);

it('initialises inside the host transaction and rolls back with it', function (): void {
    DB::beginTransaction();
    Listing::factory()->create();
    DB::rollBack();

    expect(LifecycleState::query()->count())->toBe(0)
        ->and(LifecycleTransition::query()->count())->toBe(0)
        ->and(Listing::query()->count())->toBe(0);
});

it('does not run hooks or stamps on creation', function (): void {
    $listing = Listing::factory()->create(['status' => ListingStatus::Active]);

    expect($listing->published_at)->toBeNull();
});
