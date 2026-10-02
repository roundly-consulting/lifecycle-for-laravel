<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Support\Racer;

/**
 * A duplicate delivery of the same idempotency key waits on the subject lock and replays —
 * the re-read under the lock is a locking read, so MySQL REPEATABLE READ cannot hide the
 * winner's committed row and turn the replay into a denial.
 */
it('replays a concurrent duplicate instead of denying it', function (): void {
    $listing = Listing::factory()->create();
    $racer = new Racer;

    DB::transaction(function () use ($listing, $racer): void {
        // A plain read first, so a MySQL snapshot exists before the lock wait.
        Lifecycles::for($listing)->idempotencyKey('hook-1')->apply('publish');

        $racer->run(function () use ($listing): string {
            $racing = Listing::on('racer')->findOrFail($listing->id);
            Listing::on('racer')->count();

            return Lifecycles::for($racing)->idempotencyKey('hook-1')->apply('publish')->replayed ? 'replayed' : 'applied';
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('replayed')
        ->and(LifecycleTransition::query()->where('kind', 'transition')->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
