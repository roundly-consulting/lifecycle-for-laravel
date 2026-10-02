<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

/**
 * An expiry is not rolled back — un-expiring is a forward transition with a fresh TTL.
 */
it('refuses to roll back an expiry', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC'));
    Lifecycles::sweep();

    expect(Lifecycles::for($listing->fresh())->canRollback()->codes())->toBe(['not_reversible']);
    Carbon::setTestNow();
});
