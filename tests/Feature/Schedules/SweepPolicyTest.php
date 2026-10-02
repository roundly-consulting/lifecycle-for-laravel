<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Events\ScheduledTransitionFailed;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function dueDocument(Closure $go): array
{
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: function (TransitionBuilder $builder) use ($go): void {
        $go($builder->allowSystem());
    }));
    $document = Document::factory()->create();
    $scheduled = Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));

    return [$document, LifecycleSchedule::query()->findOrFail($scheduled->id)];
}

it('retries a retryable denial until the attempts run out', function (): void {
    Event::fake([ScheduledTransitionFailed::class]);
    config()->set('lifecycle.schedules.max_attempts', 3);
    [, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go->when(fn () => Denial::of('busy', retryAfter: CarbonImmutable::parse('2026-10-02 11:00:00', 'UTC'))));

    expect(Lifecycles::sweep()->deferred)->toBe(1);

    $schedule->refresh();
    expect($schedule->attempts)->toBe(1)
        ->and($schedule->last_denial)->toBe('busy')
        ->and($schedule->due_at->toDateTimeString())->toBe('2026-10-02 11:00:00');

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:00:00', 'UTC'));
    expect(Lifecycles::sweep()->deferred)->toBe(1)
        ->and($schedule->fresh()?->due_at->toDateTimeString())->toBe('2026-10-02 11:05:00');

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:05:00', 'UTC'));
    expect(Lifecycles::sweep()->failed)->toBe(1)
        ->and($schedule->fresh()?->status)->toBe(ScheduleStatus::Failed)
        ->and($schedule->fresh()?->outcome)->toBe(ScheduleOutcome::MaxAttempts);

    Event::assertDispatched(ScheduledTransitionFailed::class, fn (ScheduledTransitionFailed $e): bool => $e->final && $e->attempts === 3 && $e->denials[0]->code === 'busy');
});

it('fails at once on a permanent denial', function (): void {
    Event::fake([ScheduledTransitionFailed::class]);
    [, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go->when(fn () => false, 'permanent'));

    expect(Lifecycles::sweep()->failed)->toBe(1)
        ->and($schedule->fresh()?->outcome)->toBe(ScheduleOutcome::Denied)
        ->and($schedule->fresh()?->pending_slot)->toBeNull();

    Event::assertDispatchedTimes(ScheduledTransitionFailed::class, 1);
});

it('defers while the subject is frozen and runs once the freeze lapses', function (): void {
    [$document, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go);
    Lifecycles::for($document)->freeze(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));

    expect(Lifecycles::sweep()->deferred)->toBe(1)
        ->and($schedule->fresh()?->due_at->toDateTimeString())->toBe('2026-10-02 12:00:00')
        ->and($schedule->fresh()?->attempts)->toBe(0);

    Lifecycles::for($document)->freeze();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));

    expect(Lifecycles::sweep()->deferred)->toBe(1)
        ->and($schedule->fresh()?->due_at->toDateTimeString())->toBe('2026-10-02 12:05:00');

    Lifecycles::for($document)->freeze(CarbonImmutable::parse('2026-10-02 12:01:00', 'UTC'));
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 12:05:00', 'UTC'));

    expect(Lifecycles::sweep()->executed)->toBe(1)
        ->and(Lifecycles::for($document->fresh())->isFrozen())->toBeFalse();
});

it('cancels a schedule whose state was left by a write that bypassed the engine', function (): void {
    [$document, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go);
    Document::query()->whereKey($document->id)->update(['status' => 'b']);

    // Locking the record adopts the drift, which leaves the schedule's state and cancels it.
    expect(Lifecycles::sweep()->executed)->toBe(0)
        ->and($schedule->fresh()?->outcome)->toBe(ScheduleOutcome::StateLeft);
});

it('cancels a schedule whose subject is gone', function (): void {
    [$document, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go);
    Document::query()->whereKey($document->id)->forceDelete();

    expect(Lifecycles::sweep()->cancelled)->toBe(1)
        ->and($schedule->fresh()?->outcome)->toBe(ScheduleOutcome::SubjectMissing);
});

it('treats a custom code as retryable only with a retry instant', function (): void {
    [, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go->when(fn () => Denial::of('custom')));

    expect(Lifecycles::sweep()->failed)->toBe(1)
        ->and($schedule->fresh()?->outcome)->toBe(ScheduleOutcome::Denied);
});

it('cancels a schedule of a renamed transition as denied', function (): void {
    [$document, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go);
    LifecycleSchedule::query()->whereKey($schedule->id)->update(['transition' => 'renamed', 'pending_slot' => 'renamed']);

    expect(Lifecycles::sweep()->failed)->toBe(1)
        ->and($schedule->fresh()?->last_denial)->toBe('unknown_transition');
});

it('pauses the schedules of a soft-deleted subject and resumes them on restore', function (): void {
    [$document, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go);
    $document->delete();

    expect($schedule->fresh()?->status)->toBe(ScheduleStatus::Paused)
        ->and(Lifecycles::sweep()->total())->toBe(0);

    $document->restore();

    expect($schedule->fresh()?->status)->toBe(ScheduleStatus::Pending)
        ->and(Lifecycles::sweep()->executed)->toBe(1);
});

it('pauses a due schedule found on a trashed subject', function (): void {
    [$document, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go);
    Document::query()->whereKey($document->id)->update(['deleted_at' => now()]);

    expect(Lifecycles::sweep()->skipped)->toBe(1)
        ->and($schedule->fresh()?->status)->toBe(ScheduleStatus::Paused);
});

it('respects the run limit and keeps the keyset order', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));
    config()->set('lifecycle.schedules.batch_size', 2);

    foreach (range(1, 5) as $minute) {
        Lifecycles::for(Document::factory()->create())->asSystem()
            ->schedule('go', CarbonImmutable::parse('2026-10-02 09:5'.$minute.':00', 'UTC'));
    }

    expect(Lifecycles::sweep(limit: 3)->executed)->toBe(3)
        ->and(Lifecycles::sweep()->executed)->toBe(2)
        ->and(Lifecycles::sweep()->total())->toBe(0);
});

it('cancels a schedule whose record left its state behind the engine', function (): void {
    [$document, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go);
    Document::query()->whereKey($document->id)->update(['status' => 'b']);
    LifecycleState::query()->update(['state' => 'b']);

    expect(Lifecycles::sweep()->cancelled)->toBe(1)
        ->and($schedule->fresh()?->outcome)->toBe(ScheduleOutcome::StateLeft);
});

it('cancels a schedule whose transition no longer leaves the state', function (): void {
    [$document, $schedule] = dueDocument(fn (TransitionBuilder $go) => $go);
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b', 'c'])->initial('a')->terminal('c');
        $l->transition('start')->from('a')->to('b');
        $l->transition('go')->from('b')->to('c')->allowSystem();
    });
    Lifecycles::definitions()->flush();

    expect(Lifecycles::sweep()->cancelled)->toBe(1)
        ->and($schedule->fresh()?->outcome)->toBe(ScheduleOutcome::StateLeft)
        ->and($document->fresh()?->status)->toBe('a');
});
