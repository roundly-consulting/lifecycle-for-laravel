<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Support\Racer;

/**
 * Two sessions apply the same transition to one subject: the second waits on the subject
 * lock, then decides on the committed state — one history row, the loser denied.
 */
it('lets exactly one of two concurrent applies win', function (): void {
    $listing = Listing::factory()->create();
    $racer = new Racer;

    DB::transaction(function () use ($listing, $racer): void {
        $listing->transition('publish');

        $racer->run(function () use ($listing): string {
            try {
                Listing::on('racer')->findOrFail($listing->id)->transition('publish');

                return 'applied';
            } catch (TransitionDeniedException $exception) {
                return 'denied:'.implode(',', $exception->decision()->codes());
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('denied:not_from_current_state')
        ->and($listing->fresh()?->status)->toBe(ListingStatus::Active)
        ->and(LifecycleTransition::query()->where('kind', 'transition')->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
