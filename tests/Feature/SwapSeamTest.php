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

    expect($resolve)->toThrow(InvalidLifecycleConfigurationException::class, 'extends');
})->with([
    'state' => ['lifecycle.models.state', fn () => StateModel::class()],
    'transition' => ['lifecycle.models.transition', fn () => TransitionModel::class()],
    'schedule' => ['lifecycle.models.schedule', fn () => ScheduleModel::class()],
]);

it('never falls back to the packaged model for a class that does not exist', function (string $key, mixed $value, Closure $resolve): void {
    config()->set($key, $value);

    expect($resolve)->toThrow(InvalidLifecycleConfigurationException::class, 'must be an existing class that extends');
})->with([
    'state, missing class' => ['lifecycle.models.state', 'App\\Models\\GoneState', fn () => StateModel::class()],
    'schedule, not a string' => ['lifecycle.models.schedule', 42, fn () => ScheduleModel::class()],
]);

it('reads a blank model setting as not set, so the packaged model applies', function (string $key, Closure $resolve, string $packaged): void {
    config()->set($key, '');

    expect($resolve())->toBe($packaged);
})->with([
    'state' => ['lifecycle.models.state', fn () => StateModel::class(), LifecycleState::class],
    'transition' => ['lifecycle.models.transition', fn () => TransitionModel::class(), LifecycleTransition::class],
    'schedule' => ['lifecycle.models.schedule', fn () => ScheduleModel::class(), LifecycleSchedule::class],
]);

it('reads the packaged model when the key is absent', function (): void {
    config()->set('lifecycle.models.state', null);

    expect(StateModel::class())->toBe(LifecycleState::class);
});
