<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Exceptions\SubjectTrashedException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('refuses transitions of a trashed subject', function (): void {
    $listing = Listing::factory()->create();
    $listing->delete();

    $listing->transition('publish');
})->throws(SubjectTrashedException::class);

it('keeps the records of a soft-deleted subject', function (): void {
    $listing = Listing::factory()->create();
    $listing->delete();

    expect(LifecycleState::query()->count())->toBe(1);

    $listing->restore();

    expect($listing->transition('publish')->transition)->toBe('publish');
});

it('purges records and history on a force delete', function (): void {
    $listing = Listing::factory()->create();
    $other = Listing::factory()->create();
    $listing->transition('publish');

    $listing->forceDelete();

    expect(LifecycleState::query()->count())->toBe(1)
        ->and(LifecycleTransition::query()->pluck('subject_id')->all())->toEqual([$other->id]);
});

it('keeps history on a force delete when purging is off', function (string $value): void {
    config()->set('lifecycle.history.purge_on_force_delete', $value);
    $listing = Listing::factory()->create();

    $listing->forceDelete();

    expect(LifecycleTransition::query()->count())->toBe(1);
})->with(['off', 'no', '0', 'false']);
