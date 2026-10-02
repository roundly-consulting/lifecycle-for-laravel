<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Jobs\RunScheduledTransitionJob;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('dispatches one job per schedule even when the queue stalls between sweeps', function (): void {
    Bus::fake();
    config()->set('lifecycle.schedules.queue.enabled', 'on');
    config()->set('lifecycle.schedules.queue.connection', 'redis');
    config()->set('lifecycle.schedules.queue.name', 'lifecycle');
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));
    $scheduled = Lifecycles::for(Document::factory()->create())->asSystem()->schedule('go', CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));

    $first = Lifecycles::sweep();
    $second = Lifecycles::sweep();

    expect($first->queued)->toBe(1)
        ->and($second->queued)->toBe(0)
        ->and($second->skipped)->toBe(1);

    Bus::assertDispatchedTimes(RunScheduledTransitionJob::class, 1);
    Bus::assertDispatched(RunScheduledTransitionJob::class, fn (RunScheduledTransitionJob $job): bool => $job->scheduleId === $scheduled->id
        && $job->uniqueId() === (string) $scheduled->id && $job->connection === 'redis' && $job->queue === 'lifecycle' && $job->tries === 1);
});

it('runs the schedule when the job is handled', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));
    $document = Document::factory()->create();
    $scheduled = Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));

    app()->call([new RunScheduledTransitionJob($scheduled->id), 'handle']);

    expect($document->fresh()?->status)->toBe('b');
});

it('skips a job whose schedule is no longer pending or not yet due', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));
    $document = Document::factory()->create();
    $cancelled = Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));
    Lifecycles::for($document)->cancelScheduled('go');
    $future = Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::parse('2026-10-09 09:00:00', 'UTC'));

    app()->call([new RunScheduledTransitionJob($cancelled->id), 'handle']);
    app()->call([new RunScheduledTransitionJob($future->id), 'handle']);
    app()->call([new RunScheduledTransitionJob(999), 'handle']);

    expect($document->fresh()?->status)->toBe('a');
});
