<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Support\Racer;

/**
 * Two sessions undo the same transition: one wins (subject lock, `reverts_id` unique), the
 * other then finds a state that no longer matches.
 */
it('reverts a row once under concurrent rollbacks', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $racer = new Racer;

    DB::transaction(function () use ($listing, $racer): void {
        Lifecycles::for($listing)->rollback();

        $racer->run(function () use ($listing): string {
            try {
                Lifecycles::for(Listing::on('racer')->findOrFail($listing->id))->rollback();

                return 'rolled back';
            } catch (RollbackDeniedException $exception) {
                return 'denied:'.implode(',', $exception->decision()->codes());
            }
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('denied:not_reversible')
        ->and(LifecycleTransition::query()->where('kind', 'rollback')->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
