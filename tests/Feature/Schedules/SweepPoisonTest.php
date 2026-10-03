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
use RoundlyConsulting\Lifecycle\Events\LifecycleExpiring;
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

/**
 * A warning row the current definitions cannot resolve — the model behind its morph type is no
 * longer a subject, its lifecycle was renamed, its state was removed — is reported and stops
 * warning; it never aborts the sweep before the due schedules run.
 */
it('isolates a warning row the definitions can no longer resolve', function (string $corruption): void {
    $reported = [];
    $this->app->instance(ExceptionHandler::class, new class($reported) extends Handler
    {
        public function __construct(private array &$seen)
        {
            parent::__construct(app());
        }

        public function report(Throwable $e): void
        {
            $this->seen[] = $e::class;
        }
    });

    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem(), finish: fn (TransitionBuilder $f) => $f->allowSystem());
        $l->state('b')->ttl('10 days')->warnBefore('2 days')->expiresVia('finish');
    });

    $stale = Document::factory()->create();
    $stale->transition('go');
    $healthy = Document::factory()->create();
    Lifecycles::for($healthy)->asSystem()->schedule('go', CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));

    $row = LifecycleSchedule::query()->where('subject_id', $stale->id)->sole();
    LifecycleSchedule::query()->whereKey($row->id)->toBase()->update(match ($corruption) {
        'subject type' => ['subject_type' => 'App\\Models\\RenamedDocument'],
        'lifecycle' => ['lifecycle' => 'renamed_status'],
        'state' => ['for_state' => 'removed'],
    });

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-11 10:00:00', 'UTC'));
    $result = Lifecycles::sweep();

    expect($healthy->fresh()?->status)->toBe('b')
        ->and($result->executed)->toBe(1)
        ->and($result->warned)->toBe(0)
        ->and(LifecycleSchedule::query()->find($row->id)?->next_warn_at)->toBeNull()
        ->and($reported)->toHaveCount(1);

    // The row no longer warns, so the next sweep reports nothing new.
    Lifecycles::schedules()->warn();

    expect($reported)->toHaveCount(1);
})->with(['subject type', 'lifecycle', 'state']);

it('reports a throwing expiry listener and keeps warning the other rows', function (): void {
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

    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, finish: fn (TransitionBuilder $f) => $f->allowSystem());
        $l->state('b')->ttl('10 days')->warnBefore('2 days')->expiresVia('finish');
    });

    [$first, $second] = Document::factory()->count(2)->create()->all();
    $first->transition('go');
    $second->transition('go');
    $calls = 0;
    Event::listen(LifecycleExpiring::class, function () use (&$calls): void {
        if (++$calls === 1) {
            throw new RuntimeException('listener failed');
        }
    });

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-11 10:00:00', 'UTC'));

    expect(Lifecycles::schedules()->warn())->toBe(1)
        ->and($calls)->toBe(2)
        ->and($reported)->toBe(['listener failed']);
});
