<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Exceptions\HistoryIsAppendOnlyException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

it('keeps history append-only through Eloquent', function (): void {
    $row = LifecycleTransition::factory()->create();

    expect(fn () => $row->update(['reason' => 'edited']))->toThrow(HistoryIsAppendOnlyException::class, 'cannot be updated')
        ->and(fn () => $row->delete())->toThrow(HistoryIsAppendOnlyException::class, 'Use lifecycle:prune')
        ->and(LifecycleTransition::query()->count())->toBe(1);
});

it('links history rows to their subject, actor, reverted row and schedule', function (): void {
    $listing = Listing::factory()->create();
    $user = User::factory()->create();
    $schedule = LifecycleSchedule::factory()->create();
    $row = LifecycleTransition::factory()->create([
        'subject_type' => $listing->getMorphClass(), 'subject_id' => $listing->id,
        'actor_type' => $user->getMorphClass(), 'actor_id' => $user->id, 'schedule_id' => $schedule->id,
    ]);
    $rollback = LifecycleTransition::factory()->reverting($row)->create();

    expect($row->subject?->is($listing))->toBeTrue()
        ->and($row->actor?->is($user))->toBeTrue()
        ->and($row->schedule?->is($schedule))->toBeTrue()
        ->and($row->revertedBy?->is($rollback))->toBeTrue()
        ->and($rollback->reverts?->is($row))->toBeTrue()
        ->and($rollback->kind)->toBe(TransitionKind::Rollback)
        ->and(LifecycleTransition::factory()->kind(TransitionKind::Expiry)->make()->kind)->toBe(TransitionKind::Expiry);
});

it('tells whether a state record is frozen', function (): void {
    $now = CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC');
    $listing = Listing::factory()->create();
    $user = User::factory()->create();

    $record = LifecycleState::factory()->forSubject($listing, 'other')->frozen()->create([
        'frozen_by_type' => $user->getMorphClass(), 'frozen_by_id' => $user->id,
    ]);

    expect($record->subject?->is($listing))->toBeTrue()
        ->and($record->frozenBy?->is($user))->toBeTrue()
        ->and($record->isFrozen($now))->toBeTrue()
        ->and(LifecycleState::factory()->frozen('2026-10-02 09:00:00')->make()->isFrozen($now))->toBeFalse()
        ->and(LifecycleState::factory()->frozen('2026-10-02 11:00:00')->make()->isFrozen($now))->toBeTrue()
        ->and(LifecycleState::factory()->make()->isFrozen($now))->toBeFalse();
});

it('refuses inconsistent rows', function (): void {
    expect(fn () => LifecycleState::factory()->create(['version' => -1]))->toThrow(InvalidLifecycleUsageException::class, 'negative')
        ->and(fn () => LifecycleSchedule::factory()->create(['pending_slot' => null]))->toThrow(InvalidLifecycleUsageException::class, 'holds a slot')
        ->and(fn () => LifecycleSchedule::factory()->create(['status' => ScheduleStatus::Executed]))->toThrow(InvalidLifecycleUsageException::class);
});

it('builds schedule and quota rows from their factories', function (): void {
    $schedule = LifecycleSchedule::factory()->expiry()->due()->paused()->create();
    $listing = Listing::factory()->create();
    $schedule->forceFill(['subject_type' => $listing->getMorphClass(), 'subject_id' => $listing->id])->save();

    expect($schedule->status)->toBe(ScheduleStatus::Paused)
        ->and($schedule->subject?->is($listing))->toBeTrue()
        ->and($schedule->scheduledBy)->toBeNull()
        ->and(QuotaLock::factory()->create()->created_at)->not->toBeNull();
});
