<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Accessors;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduledTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\SweepResult;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownLifecycleException;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\SubjectResolver;

/**
 * `Lifecycles::schedules()`: run due schedules, send warnings, retry a failed schedule, list
 * what is due and what failed. Mutating methods go through the manager, so the fake records
 * them.
 */
final readonly class SchedulesAccessor
{
    public function __construct(
        private LifecycleManager $manager,
        private DefinitionRegistry $registry,
    ) {}

    /**
     * Run due schedules without sending warnings (`sweep()` does both).
     */
    public function runDue(?int $limit = null, ?bool $queue = null): SweepResult
    {
        return $this->manager->runDueSchedules(new SweepOptions($limit, $queue, warnings: false));
    }

    /**
     * Send due expiry warnings; returns how many fired.
     */
    public function warn(?int $limit = null): int
    {
        return $this->manager->sendExpiryWarnings(new SweepOptions($limit));
    }

    /**
     * Put a failed schedule back to pending (attempts reset).
     */
    public function retry(int $scheduleId): bool
    {
        return $this->manager->retrySchedule($scheduleId);
    }

    /**
     * Pending schedules due by `$now` (the clock's now when null), oldest first. Read-only.
     *
     * @return Collection<int, ScheduledTransition>
     */
    public function due(?CarbonInterface $now = null, int $limit = 100): Collection
    {
        $now = $now === null ? Clock::now() : Clock::utc($now);

        return $this->transitions(ScheduleModel::query()
            ->where('status', ScheduleStatus::Pending->value)
            ->where('due_at', '<=', Clock::format($now))
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit));
    }

    /**
     * Failed schedules, the most recently failed first — with `lastDenial`, `outcome` and
     * `finishedAt` saying why. Retry one with `retry($id)` or `Lifecycles::for($subject)->retryScheduled()`.
     *
     * @return Collection<int, ScheduledTransition>
     */
    public function failed(int $limit = 100): Collection
    {
        return $this->transitions(ScheduleModel::query()
            ->where('status', ScheduleStatus::Failed->value)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($limit));
    }

    /**
     * Rows whose subject type no longer resolves to a lifecycle subject are skipped (the sweep
     * cancels the pending ones).
     *
     * @param  Builder<LifecycleSchedule>  $query
     * @return Collection<int, ScheduledTransition>
     */
    private function transitions(Builder $query): Collection
    {
        $transitions = [];

        foreach ($query->get() as $schedule) {
            try {
                $definition = $this->registry->of(SubjectResolver::classFor($schedule->subject_type), $schedule->lifecycle);
            } catch (UnknownLifecycleException) {
                continue;
            }

            $transitions[] = $schedule->toScheduled($definition);
        }

        return new Collection($transitions);
    }
}
