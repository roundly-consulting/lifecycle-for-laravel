<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleAdopted;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Exceptions\ConcurrentTransitionException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\LockedRow;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;

/**
 * The state records: found without a lock for checks, locked (and reconciled with the
 * stored attribute) inside every mutation. A missing record or one that disagrees with the
 * stored state is adopted; a NULL stored state is initialised.
 *
 * @internal
 */
final readonly class StateRecords
{
    public function __construct(
        private Dispatcher $events,
        private ScheduleBook $schedules,
    ) {}

    public function find(Model $subject, string $lifecycle): ?LifecycleState
    {
        return StateModel::of($subject, $lifecycle)->first();
    }

    /**
     * Call with the subject row locked and refreshed (SubjectLocker).
     */
    public function lock(Model $subject, string $lifecycle, CompiledDefinition $definition, bool $scheduleExpiry = true): LockedRecord
    {
        $raw = $subject->getRawOriginal($lifecycle);

        if ($raw === null) {
            return new LockedRecord($this->initializeNull($subject, $lifecycle, $definition, $scheduleExpiry), true);
        }

        $stored = $definition->key($raw);
        $now = Clock::now();

        $record = LockedRow::firstOrInsert(
            StateModel::queryFor($subject),
            ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'lifecycle' => $lifecycle],
            ['state' => $stored, 'entered_at' => $now, 'version' => 0],
        );

        // A fresh record has version 0: every write path moves it to 1 or more in the same transaction.
        if ($record->version === 0) {
            $this->adopt($subject, $lifecycle, $definition, $record, null, $stored, $scheduleExpiry);

            return new LockedRecord($record, true);
        }

        if ($record->state !== $stored) {
            $this->adopt($subject, $lifecycle, $definition, $record, $record->state, $stored, $scheduleExpiry);

            return new LockedRecord($record, true);
        }

        return new LockedRecord($record, false);
    }

    /**
     * The `created` hook: record, `initial` history row and TTL for the state the subject was
     * created in. Hooks and stamps do not run — only a transition enters a state.
     */
    public function initialize(Model $subject, string $lifecycle, CompiledDefinition $definition): void
    {
        // `created` fires before the original is synced: read the attributes just inserted.
        $raw = $subject->getAttributes()[$lifecycle] ?? null;

        if ($raw === null) {
            return;
        }

        $state = $definition->key($raw);
        $record = LockedRow::firstOrInsert(
            StateModel::queryFor($subject),
            ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'lifecycle' => $lifecycle],
            ['state' => $state, 'entered_at' => Clock::now(), 'version' => 0],
        );

        if ($record->version !== 0) {
            return;
        }

        $this->appendInitial($subject, $lifecycle, $definition, $record, $state, true);
    }

    /**
     * Rows inserted with `insert()` or a column added to an existing table: the attribute is
     * NULL, so it is set to the initial state (compare-and-swap on `IS NULL`).
     */
    private function initializeNull(Model $subject, string $lifecycle, CompiledDefinition $definition, bool $scheduleExpiry): LifecycleState
    {
        $initial = $definition->encode($definition->initial);

        $affected = $subject->newQueryWithoutScopes()
            ->whereKey($subject->getKey())
            ->whereNull($lifecycle)
            ->toBase()
            ->update([$lifecycle => $initial]);

        if ($affected !== 1) {
            throw ConcurrentTransitionException::lostRace($subject, $lifecycle);
        }

        $attributes = $subject->getAttributes();
        $attributes[$lifecycle] = $initial;
        $subject->setRawAttributes($attributes);
        $subject->syncOriginalAttribute($lifecycle);

        $record = LockedRow::firstOrInsert(
            StateModel::queryFor($subject),
            ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'lifecycle' => $lifecycle],
            ['state' => $definition->initial, 'entered_at' => Clock::now(), 'version' => 0],
        );

        $this->appendInitial($subject, $lifecycle, $definition, $record, $definition->initial, $scheduleExpiry);

        return $record;
    }

    private function appendInitial(Model $subject, string $lifecycle, CompiledDefinition $definition, LifecycleState $record, string $state, bool $scheduleExpiry): void
    {
        $now = Clock::now();
        $fresh = $record->version === 0;
        $version = $record->version + 1;

        $row = $this->append($subject, $lifecycle, TransitionKind::Initial, null, $state, $version, null);

        $record->forceFill([
            'state' => $state,
            'previous_state' => $fresh ? null : $record->state,
            'entered_at' => $now,
            'version' => $version,
        ])->save();

        if ($scheduleExpiry) {
            $this->schedules->enter($subject, $lifecycle, $definition, $state, $row->id, $now);
        }
    }

    /**
     * Drift: the stored state is the truth. The record follows it with an `adopted` row;
     * the time spent in the state starts now — the real entry time is unknowable.
     */
    private function adopt(Model $subject, string $lifecycle, CompiledDefinition $definition, LifecycleState $record, ?string $recorded, string $actual, bool $scheduleExpiry): void
    {
        $now = Clock::now();
        $version = $recorded === null ? 1 : $record->version + 1;

        $row = $this->append($subject, $lifecycle, TransitionKind::Adopted, $recorded, $actual, $version, $recorded === null ? null : $record->entered_at);

        $record->forceFill([
            'state' => $actual,
            'previous_state' => $recorded,
            'entered_at' => $now,
            'version' => $version,
        ])->save();

        if ($recorded !== null) {
            $this->schedules->leave($subject, $lifecycle, $recorded, $row->id, $now);
        }

        if ($scheduleExpiry) {
            $this->schedules->enter($subject, $lifecycle, $definition, $actual, $row->id, $now);
        }

        $value = $definition->value($actual);

        $this->events->dispatch(new LifecycleAdopted($subject, $lifecycle, $recorded, $value, $row->id));
        $this->events->dispatch(new LifecycleTransitioned(
            subject: $subject,
            subjectType: $subject->getMorphClass(),
            subjectId: self::key($subject),
            lifecycle: $lifecycle,
            kind: TransitionKind::Adopted,
            transition: null,
            from: $recorded === null ? null : ($definition->codec->tryDecode($recorded) ?? $recorded),
            to: $value,
            actor: null,
            system: true,
            historyId: $row->id,
            version: $version,
        ));
    }

    private function append(Model $subject, string $lifecycle, TransitionKind $kind, ?string $from, string $to, int $version, mixed $previousEnteredAt): LifecycleTransition
    {
        $row = TransitionModel::newFor($subject);

        $row->forceFill([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'lifecycle' => $lifecycle,
            'kind' => $kind,
            'transition' => null,
            'from_state' => $from,
            'to_state' => $to,
            'is_system' => true,
            'version' => $version,
            'previous_entered_at' => $previousEnteredAt,
            'occurred_at' => Clock::now(),
        ])->save();

        return $row;
    }

    public static function key(Model $subject): int|string
    {
        $key = $subject->getKey();

        return is_int($key) ? $key : (string) $key;
    }
}
