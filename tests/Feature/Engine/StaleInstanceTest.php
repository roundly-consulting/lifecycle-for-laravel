<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('lets check() answer from the stale model but apply() decide on the stored one', function (): void {
    $listing = Listing::factory()->create();
    $stale = Listing::query()->findOrFail($listing->id);

    $listing->transition('publish');

    expect(Lifecycles::for($stale)->can('publish'))->toBeTrue();

    try {
        $stale->transition('publish');
        $this->fail('expected a denial');
    } catch (TransitionDeniedException $exception) {
        expect($exception->decision()->codes())->toBe(['not_from_current_state'])
            ->and($stale->status)->toBe(ListingStatus::Draft);
    }
});
