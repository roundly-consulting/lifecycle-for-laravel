<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Testing;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery;
use RoundlyConsulting\Lifecycle\DataTransferObjects\CancelScheduleRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ExpiryChangeRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\FreezeRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneResult;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackResult;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduledTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduleRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\SweepResult;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionAttempt;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRecord;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\DataTransferObjects\UnfreezeRequest;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\TransitionDefinition;
use RoundlyConsulting\Lifecycle\Engine\GuardPipeline;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Enums\ExpiryChange;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Exceptions\ExpiryException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\Durations;

/**
 * Installed by `Lifecycles::fake()`. A manager subtype, so injected managers are faked too.
 *
 * It evaluates the real compiled definition's structural checks (unknown transition, wrong
 * source, terminal state, system context, a payload without `rules()`) against the in-memory
 * attribute and, when they pass, changes that attribute in memory — no database writes, no
 * events, no jobs. It keeps its own state for what it was asked to do: a fake freeze refuses
 * transitions (`frozen`) like a real one, a fake schedule can be cancelled once and is
 * forgotten when a faked transition leaves its state, and expiry changes compute the real
 * instant from the state's TTL. DB-backed checks (guards, limits, quotas) are skipped; steer
 * outcomes with `denyNext()` / `deny()`. The model hooks that only touch the in-memory model
 * (initial state, strict writes) keep their real behaviour; the DB-touching ones do nothing.
 */
final class LifecycleFake extends LifecycleManager
{
    /** @var list<RecordedCall> */
    private array $calls = [];

    /** @var array<string, Denial> */
    private array $sticky = [];

    /** @var array<string, Denial> */
    private array $once = [];

    private int $sequence = 0;

    /** @var array<string, list<TransitionResult>> applied transitions per subject lifecycle, for the fake's rollbacks */
    private array $stacks = [];

    /** @var array<string, FreezeRequest> the fake's own freezes per subject lifecycle */
    private array $frozen = [];

    /** @var array<string, array<string, string>> the fake's open schedules per subject lifecycle: transition => state it was scheduled in */
    private array $scheduled = [];

    /**
     * Deny the next application of `$transition` (one-shot).
     */
    public function denyNext(string $transition, DenialCode|string $code = DenialCode::GuardFailed, ?string $message = null): static
    {
        $this->once[$transition] = Denial::of($code, ['transition' => $transition, 'state' => ''], $message, source: 'fake');

        return $this;
    }

    /**
     * Deny every application of `$transition` from now on.
     */
    public function deny(string $transition, DenialCode|string $code, ?string $message = null): static
    {
        $this->sticky[$transition] = Denial::of($code, ['transition' => $transition, 'state' => ''], $message, source: 'fake');

        return $this;
    }

    public function apply(TransitionRequest $request): TransitionResult
    {
        $this->guardPersisted($request->subject);
        $definition = $this->definitions()->of($request->subject, $request->lifecycle);
        $from = $this->current($request->subject, $request->lifecycle, $definition);
        $transition = $this->evaluate($request, $definition, $from, consume: true);

        if ($transition instanceof Decision) {
            $this->calls[] = new RecordedCall('apply', $request, denied: $transition);

            throw TransitionDeniedException::because($transition);
        }

        $subject = $request->subject;
        $attributes = $subject->getAttributes();
        $attributes[$request->lifecycle] = $definition->encode($transition->to);
        $subject->setRawAttributes($attributes);
        $subject->syncOriginalAttribute($request->lifecycle);

        if ($from !== $transition->to) {
            // Leaving a state cancels what was scheduled in it (real: `state_left`).
            unset($this->scheduled[self::stackKey($subject, $request->lifecycle)]);
        }

        $this->sequence++;

        $result = new TransitionResult(
            subject: $subject,
            lifecycle: $request->lifecycle,
            transition: $transition->name,
            from: $definition->value($from),
            to: $definition->value($transition->to),
            record: new TransitionRecord(
                id: $this->sequence,
                lifecycle: $request->lifecycle,
                kind: TransitionKind::Transition,
                transition: $transition->name,
                from: $definition->value($from),
                to: $definition->value($transition->to),
                actorType: $request->actor?->getMorphClass(),
                actorId: $request->actor?->getKey(),
                system: $request->system,
                reason: $request->reason,
                context: $request->payload,
                snapshot: null,
                version: $this->sequence,
                revertsId: null,
                scheduleId: null,
                occurredAt: Clock::now(),
                reverted: false,
            ),
        );

        $this->calls[] = new RecordedCall('apply', $request, $result);
        $this->stacks[self::stackKey($subject, $request->lifecycle)][] = $result;

        return $result;
    }

    public function attempt(TransitionRequest $request): TransitionAttempt
    {
        try {
            return new TransitionAttempt(true, $this->apply($request), Decision::allow());
        } catch (TransitionDeniedException $exception) {
            return new TransitionAttempt(false, null, $exception->decision());
        }
    }

    public function check(TransitionRequest $request): Decision
    {
        $this->guardPersisted($request->subject);
        $definition = $this->definitions()->of($request->subject, $request->lifecycle);
        $outcome = $this->evaluate($request, $definition, $this->current($request->subject, $request->lifecycle, $definition), consume: false);

        return $outcome instanceof Decision ? $outcome : Decision::allow();
    }

    /**
     * @return list<AvailableTransition>
     */
    public function available(AvailableTransitionsQuery $query): array
    {
        $definition = $this->definitions()->of($query->subject, $query->lifecycle);
        $current = $this->current($query->subject, $query->lifecycle, $definition);
        $available = [];

        foreach ($definition->transitionsFrom($current) as $transition) {
            $decision = $this->check(new TransitionRequest($query->subject, $query->lifecycle, $transition->name, actor: $query->actor, system: $query->system));

            if ($decision->denied() && ! $query->includeDenied) {
                continue;
            }

            $available[] = new AvailableTransition(
                name: $transition->name,
                label: $transition->label(),
                to: $definition->value($transition->to),
                toLabel: $definition->stateLabel($transition->to),
                allowed: $decision->allowed,
                denials: $decision->denials,
                requiresReason: $transition->requiresReason && ! $query->system,
                payloadFields: $transition->payloadFields(),
                availableAt: null,
                meta: $transition->meta,
            );
        }

        return $available;
    }

    /**
     * Freezes in the fake's memory; false when it was already frozen until the same instant
     * for the same reason (nothing changed), like the real one.
     */
    public function freeze(FreezeRequest $request): bool
    {
        $this->guardPersisted($request->subject);
        $key = self::stackKey($request->subject, $request->lifecycle);
        $current = $this->activeFreeze($key);
        $until = $request->until === null ? null : Clock::utc($request->until);
        $sameEnd = $current?->until === null ? $until === null : $until !== null && Clock::utc($current->until)->equalTo($until);
        $changed = ! ($current !== null && $sameEnd && $current->reason === $request->reason);

        if ($changed) {
            $this->frozen[$key] = $request;
        }

        $this->calls[] = new RecordedCall('freeze', $request, $changed);

        return $changed;
    }

    /**
     * Lifts the fake's freeze; false when it was not frozen (or the freeze had lapsed).
     */
    public function unfreeze(UnfreezeRequest $request): bool
    {
        $this->guardPersisted($request->subject);
        $key = self::stackKey($request->subject, $request->lifecycle);
        $frozen = $this->activeFreeze($key) !== null;
        unset($this->frozen[$key]);

        $this->calls[] = new RecordedCall('unfreeze', $request, $frozen);

        return $frozen;
    }

    /**
     * Pops the fake's own stack of applied transitions; refused like the real one when there
     * is nothing to roll back or the point is not on it.
     */
    public function rollback(RollbackRequest $request): RollbackResult
    {
        $decision = $this->checkRollback($request);

        if ($decision->denied()) {
            $this->calls[] = new RecordedCall('rollback', $request, denied: $decision);

            throw RollbackDeniedException::because($decision);
        }

        $key = self::stackKey($request->subject, $request->lifecycle);
        $definition = $this->definitions()->of($request->subject, $request->lifecycle);
        $from = $definition->decode($this->current($request->subject, $request->lifecycle, $definition));
        $reverted = [];

        do {
            $last = array_pop($this->stacks[$key]);
            $reverted[] = $last->record;
        } while ($request->toHistoryId !== null && $this->stacks[$key] !== [] && end($this->stacks[$key])->record->id !== $request->toHistoryId);

        $to = $last->from ?? $from;
        $attributes = $request->subject->getAttributes();
        $attributes[$request->lifecycle] = $definition->encode($to);
        $request->subject->setRawAttributes($attributes);
        $request->subject->syncOriginalAttribute($request->lifecycle);

        $result = new RollbackResult($request->subject, $request->lifecycle, $from, $to, $reverted, []);
        $this->calls[] = new RecordedCall('rollback', $request, $result);

        return $result;
    }

    public function checkRollback(RollbackRequest $request): Decision
    {
        $this->guardPersisted($request->subject);
        $stack = $this->stacks[self::stackKey($request->subject, $request->lifecycle)] ?? [];
        $params = ['transition' => '', 'state' => ''];

        if ($stack === []) {
            return Decision::deny(Denial::of(DenialCode::NothingToRollback, $params, source: 'fake'));
        }

        if ($request->toHistoryId !== null) {
            $ids = array_map(static fn (TransitionResult $result): int => $result->record->id, $stack);

            if (! in_array($request->toHistoryId, $ids, true)) {
                return Decision::deny(Denial::of(DenialCode::NotOnPath, $params, source: 'fake'));
            }

            if (end($stack)->record->id === $request->toHistoryId) {
                return Decision::deny(Denial::of(DenialCode::NothingToRollback, $params, source: 'fake'));
            }
        }

        $reverted = $this->definitions()->of($request->subject, $request->lifecycle)->transition(end($stack)->transition);

        if (! ($reverted->ignoresFreeze ?? false) && $this->activeFreeze(self::stackKey($request->subject, $request->lifecycle)) !== null) {
            return Decision::deny(Denial::of(DenialCode::Frozen, $params, source: 'record'));
        }

        return Decision::allow();
    }

    public function prune(PruneOptions $options): PruneResult
    {
        $this->calls[] = new RecordedCall('prune', $options, new PruneResult);

        return new PruneResult;
    }

    /**
     * The real schedule-time structural checks (unknown transition, terminal state, wrong
     * source, a payload without `rules()`, the fake's freeze, system context), then the fake's
     * own denials; a refusal is recorded and thrown like the real one.
     */
    public function schedule(ScheduleRequest $request): ScheduledTransition
    {
        $this->guardPersisted($request->subject);
        $definition = $this->definitions()->of($request->subject, $request->lifecycle);
        $current = $this->current($request->subject, $request->lifecycle, $definition);
        $transition = $this->scheduleDecision($request, $definition, $current);

        if ($transition instanceof Decision) {
            $this->calls[] = new RecordedCall('schedule', $request, denied: $transition);

            throw TransitionDeniedException::because($transition);
        }

        $this->sequence++;
        $this->scheduled[self::stackKey($request->subject, $request->lifecycle)][$transition->name] = $current;

        $scheduled = new ScheduledTransition(
            id: $this->sequence,
            lifecycle: $request->lifecycle,
            kind: ScheduleKind::Transition,
            transition: $transition->name,
            forState: $definition->value($current),
            dueAt: Clock::utc($request->at),
            expiresAt: null,
            status: ScheduleStatus::Pending,
            attempts: 0,
            nextWarnAt: null,
        );

        $this->calls[] = new RecordedCall('schedule', $request, $scheduled);

        return $scheduled;
    }

    /**
     * True when the fake scheduled this transition and it is still open; it is then forgotten.
     */
    public function cancelScheduled(CancelScheduleRequest $request): bool
    {
        $this->guardPersisted($request->subject);
        $key = self::stackKey($request->subject, $request->lifecycle);
        $cancelled = isset($this->scheduled[$key][$request->transition]);
        unset($this->scheduled[$key][$request->transition]);

        $this->calls[] = new RecordedCall('cancelScheduled', $request, $cancelled);

        return $cancelled;
    }

    /**
     * Computes the instant the real call would set, without writing: `Set` the instant, `Renew`
     * now + the interval (else the state's TTL), `Extend` the pending expiry — read from the
     * database, so a subject created under the fake (no expiry rows) extends from now — and
     * `Clear` null. A state without an expiry throws, like the real one.
     */
    public function changeExpiry(ExpiryChangeRequest $request): ?CarbonImmutable
    {
        $this->guardPersisted($request->subject);
        $definition = $this->definitions()->of($request->subject, $request->lifecycle);
        $state = $this->current($request->subject, $request->lifecycle, $definition);
        $ttl = $definition->state($state)->ttl ?? throw ExpiryException::stateCannotExpire($state);

        $result = match ($request->change) {
            ExpiryChange::Set => Clock::utc($request->at ?? throw InvalidLifecycleUsageException::invalidRequest('expireAt() needs an instant')),
            ExpiryChange::Extend => Durations::add(
                $this->for($request->subject, $request->lifecycle)->expiresAt() ?? Clock::now(),
                $request->interval ?? throw InvalidLifecycleUsageException::invalidRequest('extend() needs an interval'),
            ),
            ExpiryChange::Renew => Durations::add(Clock::now(), $request->interval ?? $ttl->interval ?? throw ExpiryException::noTtl($state)),
            ExpiryChange::Clear => null,
        };

        $this->calls[] = new RecordedCall('changeExpiry', $request, $result);

        return $result;
    }

    public function runDueSchedules(SweepOptions $options): SweepResult
    {
        $this->calls[] = new RecordedCall('runDueSchedules', $options, new SweepResult);

        return new SweepResult;
    }

    public function sendExpiryWarnings(SweepOptions $options): int
    {
        $this->calls[] = new RecordedCall('sendExpiryWarnings', $options, 0);

        return 0;
    }

    public function retrySchedule(int $scheduleId): bool
    {
        $this->calls[] = new RecordedCall('retrySchedule', new RetriedSchedule($scheduleId), false);

        return false;
    }

    public function adopt(Model $subject, ?string $lifecycle = null): bool
    {
        $this->calls[] = new RecordedCall('adopt', $subject, false);

        return false;
    }

    public function adoptAll(string $class, ?string $lifecycle = null, int $chunk = 500, bool $scheduleExpiry = true): int
    {
        $this->calls[] = new RecordedCall('adoptAll', $this->model($class, $lifecycle), 0);

        return 0;
    }

    /**
     * @internal
     */
    public function initialize(Model $subject): void {}

    /**
     * @internal
     */
    public function subjectSaved(Model $subject): void {}

    /**
     * @internal
     */
    public function subjectDeleted(Model $subject, bool $forced): void {}

    /**
     * @internal
     */
    public function subjectRestored(Model $subject): void {}

    /**
     * @return list<RecordedCall>
     */
    public function recorded(): array
    {
        return $this->calls;
    }

    /**
     * @param  (Closure(TransitionResult): bool)|null  $callback
     */
    public function assertTransitioned(Model $subject, ?string $transition = null, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->transitions($subject, $transition, $callback),
            sprintf('Expected [%s] to be transitioned%s, but it was not.', $subject::class, $transition === null ? '' : " via [{$transition}]"),
        );
    }

    public function assertTransitionedTo(Model $subject, BackedEnum|string|int $state, ?string $lifecycle = null): void
    {
        $matching = array_filter(
            $this->transitions($subject, null, null),
            function (TransitionResult $result) use ($state, $lifecycle): bool {
                if ($lifecycle !== null && $result->lifecycle !== $lifecycle) {
                    return false;
                }

                $definition = $this->definitions()->of($result->subject, $result->lifecycle);

                return $definition->codec->tryKey($state) === $definition->key($result->to);
            },
        );

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] to be transitioned to the given state, but it was not.', $subject::class));
    }

    public function assertNotTransitioned(Model $subject, ?string $transition = null): void
    {
        PHPUnit::assertEmpty(
            $this->transitions($subject, $transition, null),
            sprintf('Expected [%s] not to be transitioned%s, but it was.', $subject::class, $transition === null ? '' : " via [{$transition}]"),
        );
    }

    public function assertNothingTransitioned(): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->result instanceof TransitionResult));

        PHPUnit::assertSame(0, $count, sprintf('Expected nothing to be transitioned, but %d transition(s) were applied.', $count));
    }

    public function assertTransitionDenied(Model $subject, ?string $transition = null, DenialCode|string|null $code = null): void
    {
        $matching = array_filter($this->calls, static function (RecordedCall $call) use ($subject, $transition, $code): bool {
            return $call->denied !== null
                && $call->request instanceof TransitionRequest
                && $call->request->subject->is($subject)
                && ($transition === null || $call->request->transition === $transition)
                && ($code === null || $call->denied->has($code));
        });

        PHPUnit::assertNotEmpty($matching, sprintf('Expected a transition of [%s] to be denied, but none was.', $subject::class));
    }

    public function assertFrozen(Model $subject, ?string $lifecycle = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->requests('freeze', $subject, $lifecycle),
            sprintf('Expected [%s] to be frozen, but it was not.', $subject::class),
        );
    }

    public function assertUnfrozen(Model $subject, ?string $lifecycle = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->requests('unfreeze', $subject, $lifecycle),
            sprintf('Expected [%s] to be unfrozen, but it was not.', $subject::class),
        );
    }

    public function assertNothingFrozen(): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === 'freeze'));

        PHPUnit::assertSame(0, $count, sprintf('Expected nothing to be frozen, but %d freeze(s) were recorded.', $count));
    }

    /**
     * @param  (Closure(RollbackResult): bool)|null  $callback
     */
    public function assertRolledBack(Model $subject, ?Closure $callback = null): void
    {
        $matching = array_filter($this->calls, static fn (RecordedCall $call): bool => $call->result instanceof RollbackResult
            && $call->result->subject->is($subject)
            && ($callback === null || $callback($call->result)));

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] to be rolled back, but it was not.', $subject::class));
    }

    public function assertNothingRolledBack(): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->result instanceof RollbackResult));

        PHPUnit::assertSame(0, $count, sprintf('Expected nothing to be rolled back, but %d rollback(s) ran.', $count));
    }

    public function assertPruned(): void
    {
        PHPUnit::assertNotEmpty(
            array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === 'prune'),
            'Expected lifecycle history to be pruned, but it was not.',
        );
    }

    public function assertNotPruned(): void
    {
        PHPUnit::assertEmpty(
            array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === 'prune'),
            'Expected lifecycle history not to be pruned, but it was.',
        );
    }

    public function assertScheduled(Model $subject, string $transition, ?CarbonInterface $at = null): void
    {
        $matching = array_filter(
            $this->requests('schedule', $subject, null),
            static fn (object $request): bool => $request instanceof ScheduleRequest && $request->transition === $transition
                && ($at === null || Clock::utc($request->at)->equalTo(Clock::utc($at))),
        );

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] to be scheduled for [%s], but it was not.', $subject::class, $transition));
    }

    public function assertNothingScheduled(): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === 'schedule'));

        PHPUnit::assertSame(0, $count, sprintf('Expected nothing to be scheduled, but %d schedule(s) were recorded.', $count));
    }

    public function assertExpiryChanged(Model $subject, ?ExpiryChange $change = null): void
    {
        $matching = array_filter(
            $this->requests('changeExpiry', $subject, null),
            static fn (object $request): bool => $request instanceof ExpiryChangeRequest && ($change === null || $request->change === $change),
        );

        PHPUnit::assertNotEmpty($matching, sprintf('Expected the expiry of [%s] to change, but it did not.', $subject::class));
    }

    public function assertNoExpiryChanged(): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === 'changeExpiry'));

        PHPUnit::assertSame(0, $count, sprintf('Expected no expiry change, but %d were recorded.', $count));
    }

    public function assertSwept(?int $times = null): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === 'runDueSchedules'));

        $times === null
            ? PHPUnit::assertGreaterThan(0, $count, 'Expected a lifecycle sweep, but none ran.')
            : PHPUnit::assertSame($times, $count, sprintf('Expected %d lifecycle sweep(s), but %d ran.', $times, $count));
    }

    public function assertNotSwept(): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === 'runDueSchedules'));

        PHPUnit::assertSame(0, $count, sprintf('Expected no lifecycle sweep, but %d ran.', $count));
    }

    public function assertAdopted(?Model $subject = null): void
    {
        $matching = array_filter($this->calls, static function (RecordedCall $call) use ($subject): bool {
            return in_array($call->method, ['adopt', 'adoptAll'], true)
                && ($subject === null || ($call->request instanceof Model && $call->request->is($subject)));
        });

        PHPUnit::assertNotEmpty($matching, 'Expected a lifecycle adoption, but none was recorded.');
    }

    public function assertNothingUnfrozen(): void
    {
        $count = $this->count('unfreeze');

        PHPUnit::assertSame(0, $count, sprintf('Expected nothing to be unfrozen, but %d unfreeze(s) were recorded.', $count));
    }

    public function assertScheduleCancelled(Model $subject, ?string $transition = null): void
    {
        $matching = array_filter(
            $this->requests('cancelScheduled', $subject, null),
            static fn (object $request): bool => $request instanceof CancelScheduleRequest && ($transition === null || $request->transition === $transition),
        );

        PHPUnit::assertNotEmpty($matching, sprintf(
            'Expected a schedule of [%s]%s to be cancelled, but none was.',
            $subject::class,
            $transition === null ? '' : " for [{$transition}]",
        ));
    }

    public function assertNothingCancelled(): void
    {
        $count = $this->count('cancelScheduled');

        PHPUnit::assertSame(0, $count, sprintf('Expected no schedule to be cancelled, but %d cancellation(s) were recorded.', $count));
    }

    public function assertWarned(?int $times = null): void
    {
        $count = $this->count('sendExpiryWarnings');

        $times === null
            ? PHPUnit::assertGreaterThan(0, $count, 'Expected expiry warnings to be sent, but none were.')
            : PHPUnit::assertSame($times, $count, sprintf('Expected %d expiry warning run(s), but %d ran.', $times, $count));
    }

    public function assertNotWarned(): void
    {
        $count = $this->count('sendExpiryWarnings');

        PHPUnit::assertSame(0, $count, sprintf('Expected no expiry warnings, but %d warning run(s) were recorded.', $count));
    }

    public function assertScheduleRetried(?int $scheduleId = null): void
    {
        $matching = array_filter(
            $this->calls,
            static fn (RecordedCall $call): bool => $call->request instanceof RetriedSchedule
                && ($scheduleId === null || $call->request->scheduleId === $scheduleId),
        );

        PHPUnit::assertNotEmpty($matching, sprintf(
            'Expected %s to be retried, but %s.',
            $scheduleId === null ? 'a schedule' : "schedule [{$scheduleId}]",
            $scheduleId === null ? 'none was' : 'it was not',
        ));
    }

    public function assertNothingRetried(): void
    {
        $count = $this->count('retrySchedule');

        PHPUnit::assertSame(0, $count, sprintf('Expected no schedule to be retried, but %d retry(s) were recorded.', $count));
    }

    public function assertNothingAdopted(): void
    {
        $count = $this->count('adopt') + $this->count('adoptAll');

        PHPUnit::assertSame(0, $count, sprintf('Expected no lifecycle adoption, but %d were recorded.', $count));
    }

    private function count(string $method): int
    {
        return count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === $method));
    }

    /**
     * The fake's freeze of a subject lifecycle, while it lasts.
     */
    private function activeFreeze(string $key): ?FreezeRequest
    {
        $freeze = $this->frozen[$key] ?? null;

        return $freeze !== null && ($freeze->until === null || Clock::utc($freeze->until)->greaterThan(Clock::now())) ? $freeze : null;
    }

    /**
     * The schedule-time subset of the real checks (GuardPipeline::evaluateSchedule()) the fake
     * can evaluate without a database, then its own denials.
     */
    private function scheduleDecision(ScheduleRequest $request, CompiledDefinition $definition, string $current): Decision|TransitionDefinition
    {
        $transition = $this->container()->make(GuardPipeline::class)->resolve($definition, $request->transition, null, $current);

        if ($transition instanceof Denial) {
            return Decision::deny($transition);
        }

        if ($request->payload !== [] && $transition->rules === []) {
            throw InvalidLifecycleUsageException::undeclaredPayload($transition->name, array_keys($request->payload));
        }

        $params = ['transition' => $transition->label(), 'state' => $definition->stateLabel($current)];
        $denials = [];

        if (! $transition->ignoresFreeze && $this->activeFreeze(self::stackKey($request->subject, $request->lifecycle)) !== null) {
            $denials[] = Denial::of(DenialCode::Frozen, $params, source: 'record');
        }

        if (! $transition->allowsSystem()) {
            $denials[] = Denial::of(DenialCode::SystemNotAllowed, $params, source: 'context');
        } elseif ($transition->systemOnly && ! $request->system) {
            $denials[] = Denial::of(DenialCode::SystemOnly, $params, source: 'context');
        }

        if ($denials !== []) {
            return Decision::from($denials);
        }

        $denial = $this->once[$transition->name] ?? $this->sticky[$transition->name] ?? null;
        unset($this->once[$transition->name]);

        return $denial === null ? $transition : Decision::deny($denial);
    }

    /**
     * Recorded requests of one method for a subject (and lifecycle).
     *
     * @return list<object>
     */
    private function requests(string $method, Model $subject, ?string $lifecycle): array
    {
        $requests = [];

        foreach ($this->calls as $call) {
            $request = $call->request;

            if ($call->method === $method && property_exists($request, 'subject') && $request->subject instanceof Model
                && $request->subject->is($subject)
                && ($lifecycle === null || (property_exists($request, 'lifecycle') && $request->lifecycle === $lifecycle))) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * @param  (Closure(TransitionResult): bool)|null  $callback
     * @return list<TransitionResult>
     */
    private function transitions(Model $subject, ?string $transition, ?Closure $callback): array
    {
        $results = [];

        foreach ($this->calls as $call) {
            $result = $call->result;

            if ($result instanceof TransitionResult && $result->subject->is($subject)
                && ($transition === null || $result->transition === $transition)
                && ($callback === null || $callback($result))) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * The real structural checks (rows 1–3 and 7) plus the fake's own denials.
     */
    private function evaluate(TransitionRequest $request, CompiledDefinition $definition, string $current, bool $consume): Decision|TransitionDefinition
    {
        $transition = $this->container()->make(GuardPipeline::class)
            ->resolve($definition, $request->transition, $request->target, $current);

        if ($transition instanceof Denial) {
            return Decision::deny($transition);
        }

        if ($request->payload !== [] && $transition->rules === []) {
            throw InvalidLifecycleUsageException::undeclaredPayload($transition->name, array_keys($request->payload));
        }

        $params = ['transition' => $transition->label(), 'state' => $definition->stateLabel($current)];
        $denials = [];

        if (! $transition->ignoresFreeze && $this->activeFreeze(self::stackKey($request->subject, $request->lifecycle)) !== null) {
            $denials[] = Denial::of(DenialCode::Frozen, $params, source: 'record');
        }

        if (! $request->system && $transition->systemOnly) {
            $denials[] = Denial::of(DenialCode::SystemOnly, $params, source: 'context');
        } elseif ($request->system && ! $transition->allowsSystem()) {
            $denials[] = Denial::of(DenialCode::SystemNotAllowed, $params, source: 'context');
        }

        if ($denials !== []) {
            return Decision::from($denials);
        }

        $denial = $this->once[$transition->name] ?? $this->sticky[$transition->name] ?? null;

        if ($consume) {
            unset($this->once[$transition->name]);
        }

        return $denial === null ? $transition : Decision::deny($denial);
    }

    private static function stackKey(Model $subject, string $lifecycle): string
    {
        return json_encode([$subject->getMorphClass(), $subject->getKey(), $lifecycle], JSON_THROW_ON_ERROR);
    }

    private function guardPersisted(Model $subject): void
    {
        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }
    }

    private function current(Model $subject, string $lifecycle, CompiledDefinition $definition): string
    {
        $raw = $subject->getAttributes()[$lifecycle] ?? null;

        return $raw === null ? throw UnknownStateException::notInitialized($subject, $lifecycle) : $definition->key($raw);
    }
}
