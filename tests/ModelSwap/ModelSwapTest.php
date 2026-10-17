<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
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

/**
 * The factories named the packaged models, so `LifecycleState::factory()` and its siblings
 * built the packaged classes after a host swapped them: the host's model events never ran.
 */
it('builds the configured models from the packaged factories', function (): void {
    CustomState::resetCreationCount();
    CustomTransition::resetCreationCount();
    CustomSchedule::resetCreationCount();

    expect(LifecycleState::factory()->create()::class)->toBe(CustomState::class)
        ->and(LifecycleTransition::factory()->create()::class)->toBe(CustomTransition::class)
        ->and(LifecycleSchedule::factory()->create()::class)->toBe(CustomSchedule::class)
        ->and(CustomState::factory()->create()::class)->toBe(CustomState::class)
        ->and(CustomState::creationCount())->toBe(2)
        ->and(CustomTransition::creationCount())->toBe(1)
        ->and(CustomSchedule::creationCount())->toBe(1);
});
