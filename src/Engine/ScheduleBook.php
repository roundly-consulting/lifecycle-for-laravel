<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\Constraints\TtlRule;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\ConcurrentTransitionException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Support\LockedRow;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;

/**
 * Keeps the schedule rows of a subject in step with its state: entering a state schedules
 * its expiry, leaving it cancels what was bound to it. At most one open row per slot
 * (`@expiry`, or the transition name). Every method runs with the subject row already
 * locked by its caller.
 *
 * @internal
 */
final readonly class ScheduleBook
{
    public const string EXPIRY_SLOT = '@expiry';

    /**
     * Entering `$state`: schedule its expiry. An open expiry row (a self-transition) is kept.
     */
    public function enter(Model $subject, string $lifecycle, CompiledDefinition $definition, string $state, int $historyId, CarbonImmutable $now): void
    {
        $ttl = $definition->state($state)->ttl;

        if ($ttl === null) {
            return;
        }

        $expiresAt = $this->resolveExpiry($subject, $ttl, $now);

        if ($expiresAt === null) {
            return;
        }

        LockedRow::firstOrInsert(
            ScheduleModel::queryFor($subject),
            $this->slotKey($subject, $lifecycle, self::EXPIRY_SLOT),
            $this->expiryValues($ttl, $state, $expiresAt, $historyId, false),
        );
    }

    /**
     * Leaving `$state`: cancel every open row bound to it (`state_left`).
     */
    public function leave(Model $subject, string $lifecycle, string $state, int $historyId, CarbonImmutable $now): void
    {
        $ids = ScheduleModel::of($subject, $lifecycle)
            ->where('for_state', $state)
            ->whereIn('status', [ScheduleStatus::Pending->value, ScheduleStatus::Paused->value])
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        ScheduleModel::queryFor($subject)->whereKey($ids)->toBase()->update([
            'status' => ScheduleStatus::Cancelled->value,
            'outcome' => ScheduleOutcome::StateLeft->value,
            'pending_slot' => null,
            'cancelled_by_transition_id' => $historyId,
            'finished_at' => Clock::format($now),
            'updated_at' => $subject->freshTimestampString(),
        ]);
    }

    /**
     * Rollback of row `$historyId`: cancel the open rows it created (`reverted`).
     */
    public function revert(Model $subject, string $lifecycle, int $historyId, int $rollbackId, CarbonImmutable $now): void
    {
        $rows = ScheduleModel::of($subject, $lifecycle)
            ->where('created_by_transition_id', $historyId)
            ->whereIn('status', [ScheduleStatus::Pending->value, ScheduleStatus::Paused->value])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $this->finish($row, ScheduleStatus::Cancelled, ScheduleOutcome::Reverted, $now, $rollbackId);
        }
    }

    /**
     * Rollback of row `$historyId`: re-open the rows it cancelled by leaving their state, with
     * their original due and expiry instants — unless their slot has been taken since.
     */
    public function reopen(Model $subject, string $lifecycle, int $historyId): void
    {
        $rows = ScheduleModel::of($subject, $lifecycle)
            ->where('cancelled_by_transition_id', $historyId)
            ->where('outcome', ScheduleOutcome::StateLeft->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $slot = $row->kind === ScheduleKind::Expiry ? self::EXPIRY_SLOT : $row->transition;

            if ($this->open($subject, $lifecycle, $slot) !== null) {
                continue;
            }

            $row->forceFill([
                'status' => ScheduleStatus::Pending,
                'pending_slot' => $slot,
                'outcome' => null,
                'finished_at' => null,
                'cancelled_by_transition_id' => null,
            ])->save();
        }
    }

    /**
     * The open row of a slot, locked.
     */
    public function open(Model $subject, string $lifecycle, string $slot): ?LifecycleSchedule
    {
        return ScheduleModel::of($subject, $lifecycle)->where('pending_slot', $slot)->lockForUpdate()->first();
    }

    /**
     * Every open row of a subject's lifecycle (pending or paused), oldest first.
     *
     * @return list<LifecycleSchedule>
     */
    public function openRows(Model $subject, string $lifecycle): array
    {
        return array_values(ScheduleModel::of($subject, $lifecycle)
            ->whereIn('status', [ScheduleStatus::Pending->value, ScheduleStatus::Paused->value])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Swap the open row of a slot for a fresh one; the old one ends `replaced`.
     *
     * @param  array<string, mixed>  $values
     */
    public function replace(Model $subject, string $lifecycle, string $slot, array $values, CarbonImmutable $now): LifecycleSchedule
    {
        $old = $this->open($subject, $lifecycle, $slot);

        if ($old !== null) {
            $this->finish($old, ScheduleStatus::Cancelled, ScheduleOutcome::Replaced, $now);
        }

        $schedule = ScheduleModel::newFor($subject);
        $schedule->forceFill([
            'attempts' => 0,
            'warnings_sent' => 0,
            'is_override' => false,
            ...$this->slotKey($subject, $lifecycle, $slot),
            ...$values,
        ])->save();

        return $schedule;
    }

    /**
     * End an open row and release its slot — a compare-and-swap on the open status.
     */
    public function finish(LifecycleSchedule $schedule, ScheduleStatus $status, ScheduleOutcome $outcome, CarbonImmutable $now, ?int $cancelledBy = null): void
    {
        $values = [
            'status' => $status->value,
            'outcome' => $outcome->value,
            'pending_slot' => null,
            'finished_at' => Clock::format($now),
            'cancelled_by_transition_id' => $cancelledBy ?? $schedule->cancelled_by_transition_id,
            'updated_at' => $schedule->freshTimestampString(),
        ];

        $affected = $schedule->newQuery()
            ->whereKey($schedule->getKey())
            ->whereIn('status', [ScheduleStatus::Pending->value, ScheduleStatus::Paused->value])
            ->toBase()
            ->update($values);

        if ($affected !== 1) {
            throw ConcurrentTransitionException::scheduleChanged($schedule->id);
        }

        $schedule->forceFill([...$values, 'status' => $status, 'outcome' => $outcome])->syncOriginal();
    }

    /**
     * A soft-deleted subject's pending rows wait (`paused`) until it is restored.
     */
    public function pause(Model $subject): void
    {
        $this->move($subject, ScheduleStatus::Pending, ScheduleStatus::Paused);
    }

    public function resume(Model $subject): void
    {
        $this->move($subject, ScheduleStatus::Paused, ScheduleStatus::Pending);
    }

    /**
     * The expiry instant on entering a state: the expiry attribute (null = never), else the
     * TTL interval or closure from now.
     */
    public function resolveExpiry(Model $subject, TtlRule $ttl, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($ttl->attribute !== null) {
            return GuardPipeline::toUtc($subject->getAttribute($ttl->attribute), $ttl->attribute)?->startOfSecond();
        }

        if ($ttl->interval !== null) {
            return Durations::add($now, $ttl->interval);
        }

        $value = $ttl->resolver === null ? null : ($ttl->resolver)($subject);

        return match (true) {
            $value === null => null,
            $value instanceof DateInterval => Durations::add($now, Durations::parse($value)),
            $value instanceof DateTimeInterface => Clock::utc($value),
            default => throw InvalidLifecycleUsageException::invalidTtl($value),
        };
    }

    /**
     * The columns of a pending expiry row.
     *
     * @return array<string, mixed>
     */
    public function expiryValues(TtlRule $ttl, string $state, CarbonImmutable $expiresAt, ?int $historyId, bool $override, ?Model $actor = null): array
    {
        return [
            'kind' => ScheduleKind::Expiry,
            'transition' => $ttl->expiresVia,
            'for_state' => $state,
            'status' => ScheduleStatus::Pending,
            'due_at' => $ttl->grace === null ? $expiresAt : Durations::add($expiresAt, $ttl->grace),
            'expires_at' => $expiresAt,
            'next_warn_at' => Warnings::first($expiresAt, $ttl->leads),
            'warnings_sent' => 0,
            'attempts' => 0,
            'is_override' => $override,
            'scheduled_by_type' => $actor?->getMorphClass(),
            'scheduled_by_id' => $actor?->getKey(),
            'created_by_transition_id' => $historyId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slotKey(Model $subject, string $lifecycle, string $slot): array
    {
        return [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'lifecycle' => $lifecycle,
            'pending_slot' => $slot,
        ];
    }

    private function move(Model $subject, ScheduleStatus $from, ScheduleStatus $to): void
    {
        ScheduleModel::queryFor($subject)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('status', $from->value)
            ->toBase()
            ->update(['status' => $to->value, 'updated_at' => $subject->freshTimestampString()]);
    }
}
