<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectTrashedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
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

it('resumes the schedules of a subject restored without model events at the next sweep', function (Closure $restore): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    $restored = Listing::factory()->create();
    $restored->transition('publish');
    $trashed = Listing::factory()->create();
    $trashed->transition('publish');
    $restored->delete();
    $trashed->delete();

    $restore($restored);
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-05 10:00:00', 'UTC')); // past the 30 days + 3 days of grace

    $result = Lifecycles::sweep();

    expect($restored->fresh()?->status)->toBe(ListingStatus::Expired)
        ->and($result->executed)->toBe(1)
        ->and(LifecycleSchedule::query()->where('subject_id', $trashed->id)->value('status'))->toBe(ScheduleStatus::Paused);

    Carbon::setTestNow();
})->with([
    'query builder' => fn (Listing $listing) => Listing::onlyTrashed()->whereKey($listing->id)->restore(),
    'restoreQuietly' => fn (Listing $listing) => $listing->restoreQuietly(),
]);
