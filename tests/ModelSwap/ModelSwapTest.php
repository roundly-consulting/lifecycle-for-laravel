<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\CustomSchedule;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\CustomState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\CustomTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('creates state records as the host class', function (): void {
    expect('lifecycle.models.state')->toHonourModelSwap(CustomState::class, function (): array {
        $listing = Listing::factory()->create();
        $listing->transition('publish');

        return $listing->lifecycleStates()->get()->all();
    });
});

it('creates history rows as the host class', function (): void {
    expect('lifecycle.models.transition')->toHonourModelSwap(CustomTransition::class, function (): array {
        $listing = Listing::factory()->create();
        $listing->transition('publish');

        return $listing->lifecycleHistory()->get()->all();
    });
});

it('creates schedule rows as the host class', function (): void {
    expect('lifecycle.models.schedule')->toHonourModelSwap(CustomSchedule::class, function (): array {
        $listing = Listing::factory()->create();
        $listing->transition('publish');

        return $listing->lifecycleSchedules()->get()->all();
    });
});
