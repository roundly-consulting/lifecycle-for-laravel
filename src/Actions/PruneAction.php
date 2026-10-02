<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneResult;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
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

        $history = $historyDays === null ? null : TransitionModel::query()
            ->where('occurred_at', '<', Clock::format($now->subDays($historyDays)));

        $schedules = $scheduleDays === null ? null : ScheduleModel::query()
            ->whereNotIn('status', [ScheduleStatus::Pending->value, ScheduleStatus::Paused->value])
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', Clock::format($now->subDays($scheduleDays)));

        if ($options->dryRun) {
            return new PruneResult($history?->count() ?? 0, $schedules?->count() ?? 0);
        }

        // Query-builder deletes: Eloquent refuses to delete history rows.
        return new PruneResult(
            $history === null ? 0 : $history->toBase()->delete(),
            $schedules === null ? 0 : $schedules->toBase()->delete(),
        );
    }

    public static function days(string $key): ?int
    {
        return config($key) === null
            ? null
            : Config::using(InvalidLifecycleConfigurationException::class)->intBetween($key, 1, 36500, 30);
    }
}
