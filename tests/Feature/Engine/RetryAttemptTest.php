<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

/**
 * A deadlock retried by Laravel's transaction() starts again from the caller's model and
 * leaves exactly one history row.
 */
it('retries a deadlocked attempt from the restore point', function (): void {
    $listing = Listing::factory()->create();
    $attempts = 0;

    Event::listen(LifecycleTransitioning::class, function (LifecycleTransitioning $event) use (&$attempts): void {
        $attempts++;

        if ($attempts === 1) {
            $event->subject->setAttribute('title', 'touched by attempt one');

            throw new QueryException('testing', 'update listings', [], new PDOException('Deadlock found when trying to get lock'));
        }

        expect($event->subject->getAttribute('title'))->not->toBe('touched by attempt one');
    });

    $listing->transition('publish');

    expect($attempts)->toBe(2)
        ->and($listing->status)->toBe(ListingStatus::Active)
        ->and(LifecycleTransition::query()->where('kind', 'transition')->count())->toBe(1);
});

it('gives up after the configured attempts', function (): void {
    config()->set('lifecycle.transaction_attempts', 2);
    $listing = Listing::factory()->create();
    $attempts = 0;

    Event::listen(LifecycleTransitioning::class, function () use (&$attempts): never {
        $attempts++;

        throw new QueryException('testing', 'update listings', [], new PDOException('Deadlock found when trying to get lock'));
    });

    expect(fn () => $listing->transition('publish'))->toThrow(QueryException::class)
        ->and($attempts)->toBe(2)
        ->and($listing->status)->toBe(ListingStatus::Draft);
});
