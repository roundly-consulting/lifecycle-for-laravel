<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Ticket;

/**
 * Every write path Eloquent fires `updating` for is intercepted; the ones without model
 * events become drift (see DriftTest).
 */
it('refuses a direct state write through every evented path', function (Closure $write): void {
    $listing = Listing::factory()->create();

    expect(fn () => $write($listing))->toThrow(DirectStateWriteException::class, 'changes only through transitions');
    expect($listing->fresh()?->status)->toBe(ListingStatus::Draft);
})->with([
    'assign + save' => [function (Listing $listing): void {
        $listing->status = ListingStatus::Active;
        $listing->save();
    }],
    'update()' => [fn (Listing $listing) => $listing->update(['status' => ListingStatus::Active])],
    'forceFill()->save()' => [fn (Listing $listing) => $listing->forceFill(['status' => 'active'])->save()],
    'push()' => [function (Listing $listing): void {
        $listing->status = ListingStatus::Active;
        $listing->push();
    }],
    'increment() with extra' => [fn (Listing $listing) => $listing->increment('user_id', 1, ['status' => 'active'])],
]);

it('lets other attributes save freely', function (): void {
    $listing = Listing::factory()->create();
    $listing->update(['title' => 'Renamed']);

    expect($listing->fresh()?->title)->toBe('Renamed');
});

it('allows a deliberate write and adopts it on save', function (): void {
    $listing = Listing::factory()->create();

    Lifecycles::allowDirectWrites(function () use ($listing): void {
        $listing->update(['status' => ListingStatus::Closed]);
    });

    $row = LifecycleTransition::query()->latest('id')->first();

    expect($listing->fresh()?->status)->toBe(ListingStatus::Closed)
        ->and($row?->kind)->toBe(TransitionKind::Adopted)
        ->and($row?->from_state)->toBe('draft')
        ->and($row?->to_state)->toBe('closed')
        ->and(LifecycleState::query()->sole()->state)->toBe('closed');
});

it('still refuses an undeclared state inside allowDirectWrites', function (): void {
    $ticket = Ticket::factory()->create();

    Lifecycles::allowDirectWrites(fn () => $ticket->update(['status' => 'nope']));
})->throws(UnknownStateException::class);

it('refuses clearing the state', function (): void {
    $ticket = Ticket::factory()->create();

    Lifecycles::allowDirectWrites(fn () => $ticket->update(['status' => null]));
})->throws(UnknownStateException::class, 'has no state yet');

it('writes freely and adopts with strict writes off', function (string $value): void {
    config()->set('lifecycle.strict_writes', $value);
    $listing = Listing::factory()->create();

    $listing->update(['status' => ListingStatus::Active]);

    expect(LifecycleState::query()->sole()->state)->toBe('active');
})->with(['off', 'no', '0', 'false']);

it('keeps the guard with any truthy strict_writes value', function (string $value): void {
    config()->set('lifecycle.strict_writes', $value);

    Listing::factory()->create()->update(['status' => ListingStatus::Active]);
})->with(['on', 'yes', '1', 'true'])->throws(DirectStateWriteException::class);

it('resets the allowance after an exception inside it', function (): void {
    $listing = Listing::factory()->create();

    rescue(fn () => Lifecycles::allowDirectWrites(fn () => throw new RuntimeException('boom')), report: false);

    $listing->update(['status' => ListingStatus::Active]);
})->throws(DirectStateWriteException::class);
