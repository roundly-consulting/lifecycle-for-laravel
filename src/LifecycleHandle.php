<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use DateInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery;
use RoundlyConsulting\Lifecycle\DataTransferObjects\CancelScheduleRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ExpiryChangeRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\FreezeRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackResult;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduledTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduleRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionAttempt;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRecord;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\DataTransferObjects\UnfreezeRequest;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Enums\ExpiryChange;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\ExpiryException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;

/**
 * One lifecycle of one subject: `Lifecycles::for($order)` or `$order->lifecycle()`.
 *
 * Immutable — `by()`, `asSystem()`, `because()`, `with()`, `expectingVersion()` and
 * `idempotencyKey()` return a new handle. Every mutation goes through the manager, so the
 * fake sees calls made through a handle. Reads use the eager-loaded relations when present
 * (`withLifecycle()`: records, open schedules, latest history rows), else query.
 */
final readonly class LifecycleHandle
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private LifecycleManager $manager,
        public Model $subject,
        public string $lifecycle,
        private ?Model $actor = null,
        private bool $system = false,
        private ?string $reason = null,
        private array $payload = [],
        private ?int $expectedVersion = null,
        private ?string $idempotencyKey = null,
    ) {}

    /**
     * Who performs the next calls (user context). The last of `by()` / `asSystem()` wins.
     */
    public function by(?Model $actor): self
    {
        return new self($this->manager, $this->subject, $this->lifecycle, $actor, false, $this->reason, $this->payload, $this->expectedVersion, $this->idempotencyKey);
    }

    /**
     * System context: no actor, actor rules skipped, only transitions that allow it.
     */
    public function asSystem(): self
    {
        return new self($this->manager, $this->subject, $this->lifecycle, null, true, $this->reason, $this->payload, $this->expectedVersion, $this->idempotencyKey);
    }

    public function because(?string $reason): self
    {
        return new self($this->manager, $this->subject, $this->lifecycle, $this->actor, $this->system, $reason, $this->payload, $this->expectedVersion, $this->idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function with(array $payload): self
    {
        return new self($this->manager, $this->subject, $this->lifecycle, $this->actor, $this->system, $this->reason, $payload, $this->expectedVersion, $this->idempotencyKey);
    }

    /**
     * Optimistic concurrency: refuse (`stale_version`) unless the record is still at this version.
     */
    public function expectingVersion(int $version): self
    {
        return new self($this->manager, $this->subject, $this->lifecycle, $this->actor, $this->system, $this->reason, $this->payload, $version, $this->idempotencyKey);
    }

    /**
     * Apply at most once per key: a repeat returns the original result (`replayed`).
     */
    public function idempotencyKey(string $key): self
    {
        return new self($this->manager, $this->subject, $this->lifecycle, $this->actor, $this->system, $this->reason, $this->payload, $this->expectedVersion, $key);
    }

    /**
     * The stored state — always what the database holds, even when an expiry is overdue.
     */
    public function state(): BackedEnum|string
    {
        $raw = $this->subject->getAttributes()[$this->lifecycle] ?? null;

        if ($raw === null) {
            throw UnknownStateException::notInitialized($this->subject, $this->lifecycle);
        }

        return $this->definition()->decode($raw);
    }

    /**
     * What the state will be once an overdue expiry runs: the expiry transition's target when
     * the pending expiry has passed (the sweep may not have run yet), else `state()`.
     */
    public function effectiveState(): BackedEnum|string
    {
        if (! $this->isExpired()) {
            return $this->state();
        }

        $definition = $this->definition();
        $expiresVia = $definition->state($definition->key($this->state()))->ttl->expiresVia ?? null;
        $transition = $expiresVia === null ? null : $definition->transition($expiresVia);

        return $transition === null ? $this->state() : $definition->value($transition->to);
    }

    public function is(BackedEnum|string|int ...$states): bool
    {
        $definition = $this->definition();
        $current = $definition->key($this->state());

        foreach ($states as $state) {
            if ($definition->codec->tryKey($state) === $current) {
                return true;
            }
        }

        return false;
    }

    public function isTerminal(): bool
    {
        $definition = $this->definition();

        return $definition->isTerminal($definition->key($this->state()));
    }

    public function enteredAt(): ?CarbonImmutable
    {
        return $this->record()?->entered_at;
    }

    /**
     * The record version (0 before the first record exists).
     */
    public function version(): int
    {
        return $this->record()->version ?? 0;
    }

    public function definition(): CompiledDefinition
    {
        return $this->manager->definitions()->of($this->subject, $this->lifecycle);
    }

    public function can(string $transition): bool
    {
        return $this->check($transition)->allowed;
    }

    public function canTransitionTo(BackedEnum|string|int $state): bool
    {
        return $this->checkTransitionTo($state)->allowed;
    }

    public function check(string $transition): Decision
    {
        return $this->manager->check($this->request($transition, null));
    }

    public function checkTransitionTo(BackedEnum|string|int $state): Decision
    {
        return $this->manager->check($this->request(null, $state));
    }

    /**
     * @return list<AvailableTransition>
     */
    public function allowedTransitions(bool $includeDenied = false): array
    {
        return $this->manager->available(new AvailableTransitionsQuery(
            $this->subject, $this->lifecycle, $this->actor, $this->system, $includeDenied,
        ));
    }

    /**
     * The states the allowed transitions lead to.
     *
     * @return list<BackedEnum|string>
     */
    public function allowedStates(): array
    {
        $states = [];

        foreach ($this->allowedTransitions() as $transition) {
            if (! in_array($transition->to, $states, true)) {
                $states[] = $transition->to;
            }
        }

        return $states;
    }

    public function apply(string $transition): TransitionResult
    {
        return $this->manager->apply($this->request($transition, null));
    }

    public function attempt(string $transition): TransitionAttempt
    {
        return $this->manager->attempt($this->request($transition, null));
    }

    /**
     * Apply the one transition from the current state to `$state`.
     */
    public function transitionTo(BackedEnum|string|int $state): TransitionResult
    {
        return $this->manager->apply($this->request(null, $state));
    }

    /**
     * Undo the last transition (all or nothing). `force` skips the snapshot-conflict check.
     */
    public function rollback(bool $force = false): RollbackResult
    {
        return $this->manager->rollback($this->rollbackRequest(null, $force));
    }

    /**
     * Revert every transition after a history row (it must be on this lifecycle's path).
     */
    public function rollbackTo(int|TransitionRecord $point, bool $force = false): RollbackResult
    {
        return $this->manager->rollback($this->rollbackRequest($point instanceof TransitionRecord ? $point->id : $point, $force));
    }

    public function canRollback(): Decision
    {
        return $this->manager->checkRollback($this->rollbackRequest(null, false));
    }

    /**
     * Whether `rollbackTo($point)` would be allowed right now.
     */
    public function canRollbackTo(int|TransitionRecord $point): Decision
    {
        return $this->manager->checkRollback($this->rollbackRequest($point instanceof TransitionRecord ? $point->id : $point, false));
    }

    /**
     * The newest history rows first.
     *
     * @return Collection<int, TransitionRecord>
     */
    public function history(int $limit = 50): Collection
    {
        $definition = $this->definition();

        if (! $this->subject->exists) {
            return new Collection;
        }

        return TransitionModel::of($this->subject, $this->lifecycle)
            ->with('revertedBy')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (LifecycleTransition $row): TransitionRecord => $row->toRecord($definition))
            ->values()
            ->toBase();
    }

    /**
     * The newest history row — from the eager-loaded `lifecycleLatestTransitions` relation when
     * present (`withLifecycle()`), else queried.
     */
    public function lastTransition(): ?TransitionRecord
    {
        if (! $this->subject->relationLoaded('lifecycleLatestTransitions')) {
            return $this->history(1)->first();
        }

        $loaded = $this->subject->getRelation('lifecycleLatestTransitions');

        foreach (is_iterable($loaded) ? $loaded : [] as $row) {
            if ($row instanceof LifecycleTransition && $row->lifecycle === $this->lifecycle) {
                return $row->toRecord($this->definition());
            }
        }

        return null;
    }

    /**
     * Freeze until an instant, or until unfrozen; uses the handle's reason and actor (the
     * actor is recorded for audit only — who may freeze is the host's policy).
     */
    public function freeze(?CarbonInterface $until = null): bool
    {
        return $this->manager->freeze(new FreezeRequest($this->subject, $this->lifecycle, $until, $this->reason, $this->actor));
    }

    public function unfreeze(): bool
    {
        return $this->manager->unfreeze(new UnfreezeRequest($this->subject, $this->lifecycle, $this->reason, $this->actor));
    }

    public function isFrozen(): bool
    {
        return $this->record()?->isFrozen(Clock::now()) ?? false;
    }

    public function frozenReason(): ?string
    {
        $record = $this->record();

        return $record !== null && $record->isFrozen(Clock::now()) ? $record->frozen_reason : null;
    }

    public function frozenUntil(): ?CarbonImmutable
    {
        $record = $this->record();

        return $record !== null && $record->isFrozen(Clock::now()) ? $record->frozen_until : null;
    }

    /**
     * Run a transition at an instant, as the system; this handle's actor, reason, payload and
     * `asSystem()` are what is checked and stored now.
     */
    public function schedule(string $transition, CarbonInterface $at): ScheduledTransition
    {
        return $this->manager->schedule(new ScheduleRequest(
            $this->subject, $this->lifecycle, $transition, $at, $this->actor, $this->system, $this->reason, $this->payload,
        ));
    }

    public function cancelScheduled(string $transition): bool
    {
        return $this->manager->cancelScheduled(new CancelScheduleRequest($this->subject, $this->lifecycle, $transition));
    }

    /**
     * The open schedules (pending or paused) of this lifecycle, soonest first.
     *
     * @return list<ScheduledTransition>
     */
    public function scheduled(): array
    {
        $definition = $this->definition();

        return array_map(
            static fn (LifecycleSchedule $schedule): ScheduledTransition => $schedule->toScheduled($definition),
            $this->openSchedules(),
        );
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiry()?->expires_at;
    }

    /**
     * A pending expiry whose instant has passed — expired, even before the sweep ran.
     */
    public function isExpired(): bool
    {
        $expiry = $this->expiry();

        return $expiry !== null && $expiry->status === ScheduleStatus::Pending
            && $expiry->expires_at !== null && $expiry->expires_at->lessThanOrEqualTo(Clock::now());
    }

    /**
     * Expired, but the grace period still runs (the expiry transition has not run yet).
     */
    public function isInGrace(): bool
    {
        return $this->isExpired() && ($this->expiry()?->due_at->greaterThan(Clock::now()) ?? false);
    }

    public function isExpiringWithin(CarbonInterval|DateInterval|string $within): bool
    {
        $expiresAt = $this->expiresAt();
        $now = Clock::now();

        return $expiresAt !== null && $expiresAt->greaterThan($now)
            && $expiresAt->lessThanOrEqualTo(Durations::add($now, Durations::parse($within)));
    }

    /**
     * Override the expiry of the current stay with an instant.
     */
    public function expireAt(CarbonInterface $at): CarbonImmutable
    {
        return $this->changeExpiry(ExpiryChange::Set, at: $at);
    }

    public function extend(CarbonInterval|DateInterval|string $by): CarbonImmutable
    {
        return $this->changeExpiry(ExpiryChange::Extend, interval: Durations::parse($by));
    }

    /**
     * Expire `$for` from now (the state's TTL when null).
     */
    public function renew(CarbonInterval|DateInterval|string|null $for = null): CarbonImmutable
    {
        return $this->changeExpiry(ExpiryChange::Renew, interval: $for === null ? null : Durations::parse($for));
    }

    /**
     * Clear the pending expiry of the current stay; true when there was one.
     */
    public function neverExpire(): bool
    {
        $had = $this->expiresAt() !== null;

        $this->manager->changeExpiry(new ExpiryChangeRequest($this->subject, $this->lifecycle, ExpiryChange::Clear, actor: $this->actor));

        return $had;
    }

    public function adopt(): bool
    {
        return $this->manager->adopt($this->subject, $this->lifecycle);
    }

    private function request(?string $transition, BackedEnum|string|int|null $target): TransitionRequest
    {
        return new TransitionRequest(
            subject: $this->subject,
            lifecycle: $this->lifecycle,
            transition: $transition,
            target: $target,
            actor: $this->actor,
            system: $this->system,
            reason: $this->reason,
            payload: $this->payload,
            expectedVersion: $this->expectedVersion,
            idempotencyKey: $this->idempotencyKey,
        );
    }

    private function rollbackRequest(?int $point, bool $force): RollbackRequest
    {
        return new RollbackRequest($this->subject, $this->lifecycle, $point, $this->actor, $this->system, $this->reason, $force);
    }

    private function changeExpiry(ExpiryChange $change, ?CarbonInterface $at = null, ?CarbonInterval $interval = null): CarbonImmutable
    {
        return $this->manager->changeExpiry(new ExpiryChangeRequest($this->subject, $this->lifecycle, $change, $at, $interval, $this->actor))
            ?? throw ExpiryException::noPendingExpiry($this->definition()->key($this->state()));
    }

    private function expiry(): ?LifecycleSchedule
    {
        foreach ($this->openSchedules() as $schedule) {
            if ($schedule->pending_slot === ScheduleBook::EXPIRY_SLOT) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * @return list<LifecycleSchedule>
     */
    private function openSchedules(): array
    {
        if ($this->subject->relationLoaded('lifecycleSchedules')) {
            $loaded = $this->subject->getRelation('lifecycleSchedules');
            $schedules = [];

            if (is_iterable($loaded)) {
                foreach ($loaded as $schedule) {
                    if ($schedule instanceof LifecycleSchedule && $schedule->lifecycle === $this->lifecycle && $schedule->status->isOpen()) {
                        $schedules[] = $schedule;
                    }
                }
            }

            usort($schedules, static fn (LifecycleSchedule $a, LifecycleSchedule $b): int => [$a->due_at, $a->id] <=> [$b->due_at, $b->id]);

            return $schedules;
        }

        return $this->subject->exists ? app(ScheduleBook::class)->openRows($this->subject, $this->lifecycle) : [];
    }

    private function record(): ?LifecycleState
    {
        if ($this->subject->relationLoaded('lifecycleStates')) {
            $records = $this->subject->getRelation('lifecycleStates');

            if (is_iterable($records)) {
                foreach ($records as $record) {
                    if ($record instanceof LifecycleState && $record->lifecycle === $this->lifecycle) {
                        return $record;
                    }
                }
            }

            return null;
        }

        return $this->subject->exists ? StateModel::of($this->subject, $this->lifecycle)->first() : null;
    }
}
