<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\DataTransferObjects\CancelScheduleRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function schedulableDocument(?Closure $go = null): Document
{
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: function (TransitionBuilder $go_) use ($go): void {
        $go_->allowSystem()->rules(['note' => 'string']);

        if ($go !== null) {
            $go($go_);
        }
    }));

    return Document::factory()->create();
}

it('runs a scheduled transition at its instant as the system', function (): void {
    Event::fake([LifecycleTransitioned::class]);
    $user = User::factory()->create();
    $seen = null;
    $document = schedulableDocument(function (TransitionBuilder $go) use (&$seen): void {
        $go->handledBy(function (TransitionContext $c) use (&$seen): void {
            $seen = [$c->system, $c->actor, $c->reason, $c->payload, $c->scheduleId];
        });
    });

    $scheduled = Lifecycles::for($document)->by($user)->because('Planned')->with(['note' => 'later'])
        ->schedule('go', CarbonImmutable::parse('2026-10-03 12:00:00', 'Europe/Bratislava'));

    expect($scheduled->kind)->toBe(ScheduleKind::Transition)
        ->and($scheduled->dueAt->toDateTimeString())->toBe('2026-10-03 10:00:00')
        ->and($scheduled->forState)->toBe('a')
        ->and($scheduled->status)->toBe(ScheduleStatus::Pending)
        ->and(Lifecycles::schedules()->due())->toHaveCount(0)
        ->and(Lifecycles::schedules()->due(CarbonImmutable::parse('2026-10-04', 'UTC'))->first()?->id)->toBe($scheduled->id);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC'));
    expect(Lifecycles::schedules()->runDue()->executed)->toBe(1);

    $row = LifecycleTransition::query()->latest('id')->firstOrFail();

    expect($document->fresh()?->status)->toBe('b')
        ->and($row->kind)->toBe(TransitionKind::Scheduled)
        ->and($row->is_system)->toBeTrue()
        ->and($row->schedule_id)->toBe($scheduled->id)
        ->and($row->context)->toEqual(['note' => 'later', 'schedule_id' => $scheduled->id, 'scheduled_by' => ['type' => $user->getMorphClass(), 'id' => $user->id]])
        ->and($row->reason)->toBe('Planned')
        ->and($seen)->toBe([true, null, 'Planned', ['note' => 'later'], $scheduled->id])
        ->and(LifecycleSchedule::query()->sole()->outcome)->toBe(ScheduleOutcome::Executed);

    Event::assertDispatched(LifecycleTransitioned::class, fn (LifecycleTransitioned $e): bool => $e->kind === TransitionKind::Scheduled);
});

it('replaces the pending schedule of a transition and cancels it on request', function (): void {
    $document = schedulableDocument();

    $first = Lifecycles::for($document)->schedule('go', CarbonImmutable::parse('2026-10-05', 'UTC'));
    $second = Lifecycles::for($document)->schedule('go', CarbonImmutable::parse('2026-10-06', 'UTC'));

    expect(LifecycleSchedule::query()->find($first->id)?->outcome)->toBe(ScheduleOutcome::Replaced)
        ->and(Lifecycles::for($document)->scheduled())->toHaveCount(1)
        ->and(Lifecycles::for($document)->cancelScheduled('go'))->toBeTrue()
        ->and(Lifecycles::for($document)->cancelScheduled('go'))->toBeFalse()
        ->and(LifecycleSchedule::query()->find($second->id)?->outcome)->toBe(ScheduleOutcome::Cancelled);
});

it('scopes cancellation to the subject', function (): void {
    $document = schedulableDocument();
    $other = Document::factory()->create();
    Lifecycles::for($document)->schedule('go', CarbonImmutable::parse('2026-10-05', 'UTC'));

    expect(Lifecycles::cancelScheduled(new CancelScheduleRequest($other, 'status', 'go')))->toBeFalse()
        ->and(Lifecycles::for($document)->scheduled())->toHaveCount(1);
});

it('cancels a schedule when its state is left', function (): void {
    $document = schedulableDocument();
    $scheduled = Lifecycles::for($document)->schedule('finish', CarbonImmutable::parse('2026-10-05', 'UTC'));
})->throws(TransitionDeniedException::class);

it('cancels a schedule bound to a state that was left before it ran', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, finish: fn (TransitionBuilder $f) => $f->allowSystem());
        $l->transition('back')->from('b')->to('a');
    });
    $document = Document::factory()->create();
    $document->transition('go');
    $scheduled = Lifecycles::for($document)->schedule('finish', CarbonImmutable::parse('2026-10-05', 'UTC'));

    $document->transition('back');

    expect(LifecycleSchedule::query()->find($scheduled->id)?->outcome)->toBe(ScheduleOutcome::StateLeft);
});

it('retries a failed schedule', function (): void {
    $document = schedulableDocument(fn (TransitionBuilder $go) => $go->when(fn (TransitionContext $c) => $c->subject->getAttribute('title') !== 'blocked', 'blocked'));
    $document->update(['title' => 'blocked']);
    $scheduled = Lifecycles::for($document)->schedule('go', CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));

    expect(Lifecycles::sweep()->failed)->toBe(1)
        ->and(LifecycleSchedule::query()->find($scheduled->id)?->status)->toBe(ScheduleStatus::Failed)
        ->and(Lifecycles::schedules()->retry($scheduled->id))->toBeTrue()
        ->and(Lifecycles::schedules()->retry($scheduled->id))->toBeFalse()
        ->and(Lifecycles::schedules()->retry(999))->toBeFalse();

    $document->update(['title' => 'fine']);

    expect(Lifecycles::sweep()->executed)->toBe(1);
});

it('refuses to retry when the slot is taken or the state was left', function (): void {
    $document = schedulableDocument(fn (TransitionBuilder $go) => $go->when(fn () => false, 'never'));
    $failed = Lifecycles::for($document)->schedule('go', CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    Lifecycles::sweep();
    Lifecycles::for($document)->schedule('go', CarbonImmutable::parse('2026-10-09', 'UTC'));

    expect(Lifecycles::schedules()->retry($failed->id))->toBeFalse();

    Lifecycles::for($document)->cancelScheduled('go');
    Document::query()->whereKey($document->id)->update(['status' => 'b']);

    expect(Lifecycles::schedules()->retry($failed->id))->toBeFalse();

    Document::query()->whereKey($document->id)->forceDelete();

    expect(Lifecycles::schedules()->retry($failed->id))->toBeFalse();
});
