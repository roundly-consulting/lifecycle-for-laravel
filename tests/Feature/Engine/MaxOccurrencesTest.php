<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

/**
 * `maxOccurrences` reads the record counters: pruning history keeps the count, a rollback
 * gives the occurrence back.
 */
it('limits reopening, survives pruning and gives an occurrence back on rollback', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    foreach (range(1, 3) as $round) {
        $listing->transition('close');
        $listing->transition('reopen');
    }

    $listing->transition('close');

    expect(Lifecycles::for($listing)->check('reopen')->codes())->toBe(['max_occurrences_reached']);

    Carbon\Carbon::setTestNow(CarbonImmutable::now()->addDays(2));
    $pruned = Lifecycles::prune(new PruneOptions(1))->historyDeleted;
    Carbon\Carbon::setTestNow();

    expect($pruned)->toBe(9)
        ->and(Lifecycles::for($listing)->can('reopen'))->toBeFalse();
});

it('gives the occurrence back when the reopen is rolled back', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    foreach (range(1, 3) as $round) {
        $listing->transition('close');
        $listing->transition('reopen');
    }

    Lifecycles::for($listing)->rollback();

    expect(Lifecycles::for($listing)->can('reopen'))->toBeTrue();
});
