<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduledTransition;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/**
 * A document whose `go` is scheduled now and refused by `$guard` when it runs.
 */
function scheduledDocument(Closure $guard): Document
{
    $document = Document::factory()->create();
    Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::now());
    InlineGuard::$guard = $guard;

    return $document;
}

final class InlineGuard
{
    public static ?Closure $guard = null;
}

beforeEach(function (): void {
    InlineGuard::$guard = null;
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->allowSystem()
        ->when(fn () => (InlineGuard::$guard ?? fn () => true)())));
});

it('says which subject a due schedule belongs to', function (): void {
    $document = scheduledDocument(fn () => true);
    $due = Lifecycles::schedules()->due()->first();

    expect($due)->toBeInstanceOf(ScheduledTransition::class)
        ->and($due?->subjectType)->toBe($document->getMorphClass())
        ->and($due?->subjectId)->toBe($document->id)
        ->and($due?->lastDenial)->toBeNull()
        ->and($due?->outcome)->toBeNull()
        ->and($due?->finishedAt)->toBeNull()
        ->and(Lifecycles::for($document)->scheduled()[0]->subjectId)->toBe($document->id);
});

it('lists failed schedules newest first, with why they failed', function (): void {
    config()->set('lifecycle.schedules.max_attempts', 1);
    $retryable = scheduledDocument(fn () => Denial::of('busy', retryAfter: CarbonImmutable::now()->addHour()));
    Lifecycles::sweep();

    Carbon::setTestNow(CarbonImmutable::now()->addMinute());
    $permanent = scheduledDocument(fn () => Denial::of('blocked'));
    Lifecycles::sweep();

    $failed = Lifecycles::schedules()->failed();

    expect($failed)->toHaveCount(2)
        ->and($failed->map(fn (ScheduledTransition $s): int|string => $s->subjectId)->all())->toBe([$permanent->id, $retryable->id])
        ->and($failed[0]->status)->toBe(ScheduleStatus::Failed)
        ->and($failed[0]->outcome)->toBe(ScheduleOutcome::Denied)
        ->and($failed[0]->lastDenial)->toBe('blocked')
        ->and($failed[0]->finishedAt?->toDateTimeString())->toBe('2026-10-02 10:01:00')
        ->and($failed[1]->outcome)->toBe(ScheduleOutcome::MaxAttempts)
        ->and($failed[1]->lastDenial)->toBe('busy')
        ->and($failed[1]->finishedAt?->toDateTimeString())->toBe('2026-10-02 10:00:00')
        ->and(Lifecycles::schedules()->failed(limit: 1))->toHaveCount(1)
        ->and(app(LifecycleManager::class)->schedules()->failed()->first()?->subjectId)->toBe($permanent->id);
});

it('skips rows whose subject type no longer resolves', function (): void {
    scheduledDocument(fn () => true);
    $orphan = LifecycleSchedule::query()->firstOrFail()->replicate();
    $orphan->forceFill(['subject_type' => 'App\Models\Gone', 'pending_slot' => 'go'])->save();
    $failedOrphan = $orphan->replicate();
    $failedOrphan->forceFill(['status' => ScheduleStatus::Failed, 'pending_slot' => null, 'finished_at' => CarbonImmutable::now()])->save();

    expect(Lifecycles::schedules()->due())->toHaveCount(1)
        ->and(Lifecycles::schedules()->failed())->toHaveCount(0);
});

it('retries this subject\'s failed schedule only', function (): void {
    $document = scheduledDocument(fn () => Denial::of('blocked'));
    $other = scheduledDocument(fn () => Denial::of('blocked'));
    Lifecycles::sweep();
    InlineGuard::$guard = fn () => true;

    expect(Lifecycles::for($document)->retryScheduled('finish'))->toBeFalse()
        ->and(Lifecycles::for($document)->retryScheduled('go'))->toBeTrue()
        ->and(Lifecycles::for($document)->retryScheduled('go'))->toBeFalse()
        ->and(LifecycleSchedule::query()->where('subject_id', $other->id)->sole()->status)->toBe(ScheduleStatus::Failed)
        ->and(LifecycleSchedule::query()->where('subject_id', $document->id)->sole()->status)->toBe(ScheduleStatus::Pending);

    Lifecycles::sweep();

    expect($document->fresh()?->status)->toBe('b')
        ->and($other->fresh()?->status)->toBe('a');
});

it('retries an expiry by its expiry transition', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $expiry = LifecycleSchedule::query()->where('subject_id', $listing->id)->sole();
    $expiry->forceFill(['status' => ScheduleStatus::Failed, 'pending_slot' => null, 'outcome' => ScheduleOutcome::Error, 'finished_at' => CarbonImmutable::now()])->save();

    expect(Lifecycles::for($listing)->retryScheduled('expire'))->toBeTrue()
        ->and($expiry->fresh()?->status)->toBe(ScheduleStatus::Pending);
});

it('records a subject-scoped retry under the fake', function (): void {
    $document = scheduledDocument(fn () => Denial::of('blocked'));
    Lifecycles::sweep();
    $id = LifecycleSchedule::query()->sole()->id;
    $fake = Lifecycles::fake();

    expect(Lifecycles::for($document)->retryScheduled('nope'))->toBeFalse();

    $fake->assertNothingRetried();
    Lifecycles::for($document)->retryScheduled('go');
    $fake->assertScheduleRetried($id);

    $scheduled = Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::now()->addDay());

    expect($scheduled->subjectType)->toBe($document->getMorphClass())
        ->and($scheduled->subjectId)->toBe($document->id);
});
