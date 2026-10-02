<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\CompensatesTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackResult;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleRolledBack;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;
use RoundlyConsulting\Lifecycle\Support\WriteGuard;

/**
 * Reverts the planned rows, newest first, inside the caller's transaction: state back with
 * a compare-and-swap, snapshot attributes and stamps restored, the handler compensated, a
 * `rollback` row appended, the record (entry time, counters) and the schedules restored.
 * State hooks do not run; rate-limit hits are not refunded.
 *
 * @internal
 */
final readonly class RollbackExecutor
{
    public function __construct(
        private Container $container,
        private Dispatcher $events,
        private TransitionExecutor $transitions,
        private ScheduleBook $schedules,
        private WriteGuard $writes,
    ) {}

    public function execute(RollbackPlan $plan, Model $subject, string $lifecycle, CompiledDefinition $definition, LifecycleState $record, RollbackRequest $request, ?Model $actor): RollbackResult
    {
        $now = Clock::now();
        $start = $definition->key($subject->getRawOriginal($lifecycle));
        $reverted = [];
        $records = [];

        foreach ($plan->targets as $row) {
            $from = $row->to_state;
            $to = (string) $row->from_state;
            $self = $from === $to;
            $transition = $definition->transition((string) $row->transition);

            $this->transitions->write($subject, $lifecycle, $definition, $from, $to, []);

            $this->writes->engine(function () use ($subject, $row, $transition, $definition, $from, $to, $actor, $request, $now): void {
                $before = $row->snapshot['before'] ?? null;

                if (is_array($before) && $before !== []) {
                    $attributes = $subject->getAttributes();

                    foreach ($before as $attribute => $value) {
                        $attributes[(string) $attribute] = $value;
                    }

                    $subject->setRawAttributes($attributes);
                }

                $handler = $transition?->handler;
                $handler = is_string($handler) ? $this->container->make($handler) : $handler;

                if ($handler instanceof CompensatesTransition) {
                    $handler->compensate(new RollbackContext(
                        subject: $subject,
                        lifecycle: $row->lifecycle,
                        reverted: $row->toRecord($definition, reverted: true),
                        from: $definition->value($from),
                        to: $definition->value($to),
                        actor: $actor,
                        reason: $request->reason,
                        now: $now,
                    ));
                }

                if ($subject->isDirty()) {
                    $subject->save();
                }
            });

            $version = $record->version + 1;
            $rollback = TransitionModel::newFor($subject);
            $rollback->forceFill([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'lifecycle' => $lifecycle,
                'kind' => TransitionKind::Rollback,
                'transition' => $row->transition,
                'from_state' => $from,
                'to_state' => $to,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'is_system' => $request->system,
                'reason' => $request->reason,
                'previous_entered_at' => $record->entered_at,
                'counter_before' => CounterBook::entry($record->counters, (string) $row->transition),
                'version' => $version,
                'reverts_id' => $row->id,
                'occurred_at' => $now,
            ])->save();

            $record->forceFill([
                'state' => $to,
                'previous_state' => $from,
                'entered_at' => $row->previous_entered_at ?? $now,
                'version' => $version,
                'counters' => CounterBook::restore($record->counters, (string) $row->transition, $row->counter_before),
            ])->save();

            $this->schedules->revert($subject, $lifecycle, $row->id, $rollback->id, $now);

            if (! $self) {
                $this->schedules->leave($subject, $lifecycle, $from, $rollback->id, $now);
            }

            $this->schedules->reopen($subject, $lifecycle, $row->id);

            $this->events->dispatch(new LifecycleTransitioned(
                subject: $subject,
                subjectType: $subject->getMorphClass(),
                subjectId: StateRecords::key($subject),
                lifecycle: $lifecycle,
                kind: TransitionKind::Rollback,
                transition: $row->transition,
                from: $definition->value($from),
                to: $definition->value($to),
                actor: $actor,
                system: $request->system,
                historyId: $rollback->id,
                version: $version,
            ));

            $reverted[] = $row->toRecord($definition, reverted: true);
            $records[] = $rollback->toRecord($definition, reverted: false);
        }

        $final = $definition->value((string) $plan->finalState);

        $this->events->dispatch(new LifecycleRolledBack(
            subject: $subject,
            lifecycle: $lifecycle,
            from: $definition->value($start),
            to: $final,
            revertedIds: array_map(static fn ($record): int => $record->id, $reverted),
            rollbackIds: array_map(static fn ($record): int => $record->id, $records),
            actor: $actor,
        ));

        return new RollbackResult($subject, $lifecycle, $definition->value($start), $final, $reverted, $records);
    }
}
