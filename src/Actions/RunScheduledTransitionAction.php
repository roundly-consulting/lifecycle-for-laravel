<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\ContextFactory;
use RoundlyConsulting\Lifecycle\Engine\GuardPipeline;
use RoundlyConsulting\Lifecycle\Engine\Mode;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Engine\ScheduleRun;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Engine\SubjectLocker;
use RoundlyConsulting\Lifecycle\Engine\TransitionExecutor;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleExpired;
use RoundlyConsulting\Lifecycle\Events\ScheduledTransitionFailed;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\SoftDeletion;
use RoundlyConsulting\Lifecycle\Support\SubjectResolver;
use RoundlyConsulting\Lifecycle\Support\Transactions;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

/**
 * Executes one due schedule, always in system context, in its own transaction: subject
 * locked first, then its record, then the schedule row. A denial with only retryable codes
 * is retried later (until `schedules.max_attempts`), any other denial fails it; a schedule
 * whose state was left is cancelled; a frozen subject defers it. An exception never poisons
 * the sweep: it is reported, counted against the attempts and the sweep moves on — inline
 * and queued alike.
 *
 * @internal
 */
final readonly class RunScheduledTransitionAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private Dispatcher $events,
        private ExceptionHandler $exceptions,
        private GuardPipeline $pipeline,
        private ContextFactory $contexts,
        private StateRecords $records,
        private SubjectLocker $locker,
        private ScheduleBook $schedules,
        private TransitionExecutor $executor,
    ) {}

    public function execute(int $scheduleId, CarbonImmutable $now): ScheduleRun
    {
        $schedule = ScheduleModel::query()->find($scheduleId);

        if ($schedule === null || $schedule->status !== ScheduleStatus::Pending || $schedule->due_at->greaterThan($now)) {
            return ScheduleRun::Skipped;
        }

        try {
            $subject = SubjectResolver::find($schedule->subject_type, $schedule->subject_id);

            if ($subject === null) {
                Transactions::run($schedule, fn () => $this->schedules->finish($schedule, ScheduleStatus::Cancelled, ScheduleOutcome::SubjectMissing, $now));

                return ScheduleRun::Cancelled;
            }

            if (SoftDeletion::isTrashed($subject)) {
                $this->schedules->pause($subject);

                return ScheduleRun::Skipped;
            }

            return Transactions::run(
                $subject,
                fn (): ScheduleRun => $this->run($subject, $scheduleId, $schedule->lifecycle, $now),
                countsQuotas: $this->registry->of($subject, $schedule->lifecycle)->quotasOnTarget($schedule->transition, null),
            );
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);

            return $this->recordError($scheduleId, $now);
        }
    }

    private function run(Model $subject, int $scheduleId, string $lifecycle, CarbonImmutable $now): ScheduleRun
    {
        // Lock order: the subject, its record (adopting drift, which may cancel this very
        // schedule), then the schedule row.
        $this->locker->lock($subject);

        $definition = $this->registry->of($subject, $lifecycle);
        $record = $this->records->lock($subject, $lifecycle, $definition)->record;

        $schedule = ScheduleModel::queryFor($subject)
            ->whereKey($scheduleId)
            ->where('status', ScheduleStatus::Pending->value)
            ->lockForUpdate()
            ->first();

        if ($schedule === null) {
            return ScheduleRun::Skipped;
        }

        if ($record->state !== $schedule->for_state) {
            $this->schedules->finish($schedule, ScheduleStatus::Cancelled, ScheduleOutcome::StateLeft, $now);

            return ScheduleRun::Cancelled;
        }

        if ($record->frozen_at !== null) {
            if (! $record->isFrozen($now)) {
                // A lapsed freeze is cleared here; the schedule is due, so it runs now.
                $record->forceFill(['frozen_at' => null, 'frozen_until' => null, 'frozen_reason' => null, 'frozen_by_type' => null, 'frozen_by_id' => null])->save();
            } else {
                $schedule->forceFill(['due_at' => $record->frozen_until ?? Durations::add($now, self::retryAfter())])->save();

                return ScheduleRun::Deferred;
            }
        }

        $context = $schedule->context ?? [];
        $payload = is_array($context['payload'] ?? null) ? $context['payload'] : [];
        $reason = is_string($context['reason'] ?? null) ? $context['reason'] : null;
        $transition = $this->pipeline->resolve($definition, $schedule->transition, null, $record->state);

        if ($transition instanceof Denial) {
            return $this->denied($subject, $schedule, Decision::deny($transition), $now);
        }

        $evaluation = $this->contexts->evaluation(
            $definition,
            new TransitionRequest($subject, $lifecycle, $transition->name, system: true, reason: $reason, payload: $payload),
            $transition,
            $record->state,
            Mode::Apply,
            $record,
            null,
            $schedule->id,
            sweep: true,
        );

        $decision = $this->pipeline->evaluate($evaluation);

        if ($decision->denied()) {
            return $this->denied($subject, $schedule, $decision, $now);
        }

        // Finished first, so leaving the state does not cancel the schedule being run.
        $this->schedules->finish($schedule, ScheduleStatus::Executed, ScheduleOutcome::Executed, $now);

        $expiry = $schedule->kind === ScheduleKind::Expiry;
        $result = $this->executor->perform(
            $evaluation,
            $record,
            $expiry ? TransitionKind::Expiry : TransitionKind::Scheduled,
            null,
            array_filter([
                'schedule_id' => $schedule->id,
                'scheduled_by' => $schedule->scheduled_by_type === null ? null : ['type' => $schedule->scheduled_by_type, 'id' => $schedule->scheduled_by_id],
            ], static fn (mixed $value): bool => $value !== null),
        );

        if ($expiry && $result->from !== null) {
            $this->events->dispatch(new LifecycleExpired(
                $subject,
                $lifecycle,
                $result->from,
                $result->to,
                $result->record->id,
                $schedule->expires_at ?? $schedule->due_at,
            ));
        }

        return ScheduleRun::Executed;
    }

    /**
     * Retryable denials only: try again later (until the attempts run out). The state was
     * left: cancel. Anything permanent: fail.
     */
    private function denied(Model $subject, LifecycleSchedule $schedule, Decision $decision, CarbonImmutable $now): ScheduleRun
    {
        if ($decision->has(DenialCode::NotFromCurrentState)) {
            $this->schedules->finish($schedule, ScheduleStatus::Cancelled, ScheduleOutcome::StateLeft, $now);

            return ScheduleRun::Cancelled;
        }

        $attempts = $schedule->attempts + 1;

        if ($decision->isRetryable() && $attempts < self::maxAttempts()) {
            $retry = Durations::add($now, self::retryAfter());
            $due = $decision->retryAfter !== null && $decision->retryAfter->greaterThan($retry) ? $decision->retryAfter : $retry;

            $schedule->forceFill(['attempts' => $attempts, 'due_at' => $due, 'last_denial' => $decision->first()?->code])->save();

            return ScheduleRun::Deferred;
        }

        $schedule->forceFill(['attempts' => $attempts, 'last_denial' => $decision->first()?->code])->save();
        $this->schedules->finish(
            $schedule,
            ScheduleStatus::Failed,
            $decision->isRetryable() ? ScheduleOutcome::MaxAttempts : ScheduleOutcome::Denied,
            $now,
        );
        $this->failed($schedule, $decision->denials, $attempts, $subject->getMorphClass());

        return ScheduleRun::Failed;
    }

    /**
     * An exception rolled the schedule's transaction back: count it in a small transaction
     * of its own, and fail the schedule once the attempts run out.
     */
    private function recordError(int $scheduleId, CarbonImmutable $now): ScheduleRun
    {
        $schedule = ScheduleModel::query()->find($scheduleId);

        if ($schedule === null || ! $schedule->status->isOpen()) {
            return ScheduleRun::Errored;
        }

        Transactions::run($schedule, function () use ($schedule, $now): void {
            $locked = $schedule->newQuery()->whereKey($schedule->id)->lockForUpdate()->first();

            if (! $locked instanceof LifecycleSchedule || $locked->status !== ScheduleStatus::Pending) {
                return;
            }

            $attempts = $locked->attempts + 1;
            $locked->forceFill(['attempts' => $attempts, 'last_denial' => 'error', 'due_at' => Durations::add($now, self::retryAfter())])->save();

            if ($attempts >= self::maxAttempts()) {
                $this->schedules->finish($locked, ScheduleStatus::Failed, ScheduleOutcome::Error, $now);
                $this->failed($locked, [], $attempts, $locked->subject_type);
            }
        });

        return ScheduleRun::Errored;
    }

    /**
     * @param  list<Denial>  $denials
     */
    private function failed(LifecycleSchedule $schedule, array $denials, int $attempts, string $subjectType): void
    {
        $this->events->dispatch(new ScheduledTransitionFailed(
            scheduleId: $schedule->id,
            subjectType: $subjectType,
            subjectId: $schedule->subject_id,
            lifecycle: $schedule->lifecycle,
            transition: $schedule->transition,
            denials: $denials,
            attempts: $attempts,
            final: true,
        ));
    }

    public static function maxAttempts(): int
    {
        return Config::using(InvalidLifecycleConfigurationException::class)->intBetween('lifecycle.schedules.max_attempts', 1, 100, 5);
    }

    public static function retryAfter(): CarbonInterval
    {
        return Durations::fromConfig('lifecycle.schedules.retry_after');
    }
}
