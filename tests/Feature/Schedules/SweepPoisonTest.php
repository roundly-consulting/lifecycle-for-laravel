<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Events\ScheduledTransitionFailed;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * One throwing schedule never poisons the sweep: it is reported, counted against its
 * attempts, and the others run — inline and queued alike.
 */
beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('isolates a throwing schedule and fails it after the attempts run out', function (bool $queued): void {
    Event::fake([ScheduledTransitionFailed::class]);
    config()->set('lifecycle.schedules.max_attempts', 2);
    config()->set('queue.default', 'sync');
    $reported = [];
    $this->app->instance(ExceptionHandler::class, new class($reported) extends Handler
    {
        public function __construct(private array &$seen)
        {
            parent::__construct(app());
        }

        public function report(Throwable $e): void
        {
            $this->seen[] = $e->getMessage();
        }
    });

    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()
        ->handledBy(function (TransitionContext $c): void {
            if ($c->subject->getAttribute('title') === 'poison') {
                throw new RuntimeException('poisoned');
            }
        })));

    $poison = Document::factory()->create(['title' => 'poison']);
    $healthy = Document::factory()->create(['title' => 'fine']);
    $at = CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC');
    $poisoned = Lifecycles::for($poison)->asSystem()->schedule('go', $at);
    Lifecycles::for($healthy)->asSystem()->schedule('go', $at->addMinute());

    $result = Lifecycles::sweep(queue: $queued);

    expect($healthy->fresh()?->status)->toBe('b')
        ->and($poison->fresh()?->status)->toBe('a')
        ->and(LifecycleSchedule::query()->find($poisoned->id)?->attempts)->toBe(1)
        ->and(LifecycleSchedule::query()->find($poisoned->id)?->last_denial)->toBe('error')
        ->and($reported)->toBe(['poisoned']);

    if (! $queued) {
        expect($result->errored)->toBe(1)->and($result->executed)->toBe(1);
    }

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:05:00', 'UTC'));
    Lifecycles::sweep(queue: $queued);

    expect(LifecycleSchedule::query()->find($poisoned->id)?->outcome)->toBe(ScheduleOutcome::Error);
    Event::assertDispatched(ScheduledTransitionFailed::class, fn (ScheduledTransitionFailed $e): bool => $e->final && $e->denials === []);
})->with(['inline' => false, 'queued' => true]);
