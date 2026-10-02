<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Exceptions\IdempotencyConflictException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('replays an applied key without running anything again', function (): void {
    $listing = Listing::factory()->create();
    $stale = Listing::query()->findOrFail($listing->id);
    $first = Lifecycles::for($listing)->idempotencyKey('webhook-1')->apply('publish');

    $again = Lifecycles::for($listing)->idempotencyKey('webhook-1')->apply('publish');
    $fromStale = Lifecycles::for($stale)->idempotencyKey('webhook-1')->transitionTo(ListingStatus::Active);

    expect($again->replayed)->toBeTrue()
        ->and($again->record->id)->toBe($first->record->id)
        ->and($again->from)->toBe(ListingStatus::Draft)
        ->and($again->to)->toBe(ListingStatus::Active)
        ->and($fromStale->replayed)->toBeTrue()
        ->and($stale->status)->toBe(ListingStatus::Draft)
        ->and(LifecycleTransition::query()->where('kind', 'transition')->count())->toBe(1);
});

it('refuses the same key for another transition', function (Closure $call): void {
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->idempotencyKey('k')->apply('publish');

    expect(fn () => $call($listing))->toThrow(IdempotencyConflictException::class, 'already used for [publish]');
})->with([
    'by name' => [fn (Listing $l) => Lifecycles::for($l)->idempotencyKey('k')->apply('close')],
    'by target' => [fn (Listing $l) => Lifecycles::for($l)->idempotencyKey('k')->transitionTo(ListingStatus::Closed)],
]);

it('stores no key for a denied attempt', function (): void {
    $listing = Listing::factory()->create();

    expect(fn () => Lifecycles::for($listing)->idempotencyKey('k')->apply('close'))->toThrow(TransitionDeniedException::class);

    expect(Lifecycles::for($listing)->idempotencyKey('k')->apply('publish')->replayed)->toBeFalse();
});

it('validates the request shape', function (Closure $make, string $message): void {
    expect($make)->toThrow(InvalidLifecycleUsageException::class, $message);
})->with([
    'neither transition nor target' => [fn () => new TransitionRequest(new Listing, 'status'), 'exactly one'],
    'both' => [fn () => new TransitionRequest(new Listing, 'status', 'publish', 'active'), 'exactly one'],
    'empty key' => [fn () => new TransitionRequest(new Listing, 'status', 'publish', idempotencyKey: ''), '1 to 191'],
    'long key' => [fn () => new TransitionRequest(new Listing, 'status', 'publish', idempotencyKey: str_repeat('k', 192)), '1 to 191'],
]);
