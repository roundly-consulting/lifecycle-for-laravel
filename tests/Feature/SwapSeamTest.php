<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('documents every swap seam', function (): void {
    expect(LifecycleState::class)->toBeSwappableVia('lifecycle.models.state')
        ->and(LifecycleTransition::class)->toBeSwappableVia('lifecycle.models.transition')
        ->and(LifecycleSchedule::class)->toBeSwappableVia('lifecycle.models.schedule');
});

it('refuses a swapped model that does not extend the package model', function (): void {
    config()->set('lifecycle.models.state', Listing::class);

    StateModel::class();
})->throws(InvalidLifecycleConfigurationException::class, 'must extend');
