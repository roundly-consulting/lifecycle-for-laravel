<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use Throwable;

/**
 * Deletes old history rows and finished schedule rows. Defaults come from
 * `history.prune_after_days` and `schedules.prune_after_days`.
 */
final class PruneCommand extends Command
{
    protected $signature = 'lifecycle:prune
        {--history-days= : Delete history rows older than this many days}
        {--schedule-days= : Delete finished schedule rows older than this many days}
        {--dry-run : Only count what would be deleted}
        {--database= : The database connection whose package tables to prune (default: the default connection)}';

    protected $description = 'Prune old lifecycle history and finished schedules';

    public function handle(LifecycleManager $lifecycle): int
    {
        try {
            $history = IntegerOption::parse($this->option('history-days'), 'history-days');
            $schedules = IntegerOption::parse($this->option('schedule-days'), 'schedule-days');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $configured = $history ?? config('lifecycle.history.prune_after_days') ?? $schedules ?? config('lifecycle.schedules.prune_after_days');

        if ($configured === null) {
            $this->info('Nothing to prune: no retention is configured.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run') === true;
        $database = $this->option('database');
        $result = $lifecycle->prune(new PruneOptions($history, $schedules, $dryRun, is_string($database) && $database !== '' ? $database : null));

        $this->info(sprintf(
            '%s %d history row(s) and %d schedule row(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $result->historyDeleted,
            $result->schedulesDeleted,
        ));

        return self::SUCCESS;
    }
}
