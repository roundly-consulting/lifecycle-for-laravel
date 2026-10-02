<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('documents every swap seam', function (): void {
    expect(LifecycleState::class)->toBeSwappableVia('lifecycle.models.state')
        ->and(LifecycleTransition::class)->toBeSwappableVia('lifecycle.models.transition')
        ->and(LifecycleSchedule::class)->toBeSwappableVia('lifecycle.models.schedule');
});

it('refuses a swapped model that does not extend the package model', function (string $key, Closure $resolve): void {
    config()->set($key, Listing::class);

    expect($resolve)->toThrow(InvalidLifecycleConfigurationException::class, 'must extend');
})->with([
    'state' => ['lifecycle.models.state', fn () => StateModel::class()],
    'transition' => ['lifecycle.models.transition', fn () => TransitionModel::class()],
    'schedule' => ['lifecycle.models.schedule', fn () => ScheduleModel::class()],
]);
