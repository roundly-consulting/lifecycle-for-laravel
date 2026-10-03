<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneResult;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Deletes history rows and finished schedule rows older than the given (or configured)
 * number of days. Limits and cooldowns live on the state records, so pruning never resets
 * them; it does drop rollback points and idempotency keys older than the cutoff.
 * Authorization is the host's.
 */
final readonly class PruneAction
{
    public function execute(PruneOptions $options): PruneResult
    {
        $historyDays = $options->historyOlderThanDays ?? self::days('lifecycle.history.prune_after_days');
        $scheduleDays = $options->schedulesOlderThanDays ?? self::days('lifecycle.schedules.prune_after_days');
        $now = Clock::now();

        $history = $historyDays === null ? null : TransitionModel::query($options->connection)
            ->where('occurred_at', '<', Clock::format($now->subDays($historyDays)));

        $schedules = $scheduleDays === null ? null : self::keepRunningStayMarks(ScheduleModel::query($options->connection)
            ->whereNotIn('status', [ScheduleStatus::Pending->value, ScheduleStatus::Paused->value])
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', Clock::format($now->subDays($scheduleDays))));

        if ($options->dryRun) {
            return new PruneResult($history?->count() ?? 0, $schedules?->count() ?? 0);
        }

        // Query-builder deletes: Eloquent refuses to delete history rows.
        return new PruneResult(
            $history === null ? 0 : $history->toBase()->delete(),
            $schedules === null ? 0 : $schedules->toBase()->delete(),
        );
    }

    /**
     * A `neverExpire()` mark (a cancelled override row) stays while the subject is still in the
     * stay it was made in — pruning it would let a later change of the expiry attribute
     * schedule the expiry again.
     *
     * @param  Builder<LifecycleSchedule>  $rows
     * @return Builder<LifecycleSchedule>
     */
    private static function keepRunningStayMarks(Builder $rows): Builder
    {
        $table = $rows->getModel()->getTable();
        $states = StateModel::newFor($rows->getModel())->getTable();

        return $rows->where(static fn (Builder $kept) => $kept
            ->where($table.'.is_override', false)
            ->orWhere($table.'.outcome', '!=', ScheduleOutcome::Cancelled->value)
            ->orWhereNotExists(static fn (QueryBuilder $stay) => $stay->selectRaw('1')
                ->from($states.' as lifecycle_stay')
                ->whereColumn('lifecycle_stay.subject_type', $table.'.subject_type')
                ->whereColumn('lifecycle_stay.subject_id', $table.'.subject_id')
                ->whereColumn('lifecycle_stay.lifecycle', $table.'.lifecycle')
                ->whereColumn('lifecycle_stay.state', $table.'.for_state')
                ->whereColumn('lifecycle_stay.entered_at', '<=', $table.'.finished_at')));
    }

    public static function days(string $key): ?int
    {
        return config($key) === null
            ? null
            : Config::using(InvalidLifecycleConfigurationException::class)->intBetween($key, 1, 36500, 30);
    }
}
