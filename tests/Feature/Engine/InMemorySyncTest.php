<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('leaves the model in the new state and clean', function (): void {
    $listing = Listing::factory()->create();

    $listing->transition('publish');

    expect($listing->status)->toBe(ListingStatus::Active)
        ->and($listing->isDirty())->toBeFalse()
        ->and($listing->published_at)->not->toBeNull();

    $listing->title = 'later edit';
    $listing->save();

    expect($listing->fresh()?->status)->toBe(ListingStatus::Active);
});
