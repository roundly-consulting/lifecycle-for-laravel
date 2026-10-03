<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use Throwable;

/**
 * Sends due expiry warnings and runs due expiries and scheduled transitions. Schedule it
 * every minute; `--isolated` keeps overlapping runs out.
 */
final class SweepCommand extends Command implements Isolatable
{
    protected $signature = 'lifecycle:sweep
        {--limit= : At most this many schedules (default: schedules.max_per_run)}
        {--queue : Dispatch one job per schedule instead of running them inline}
        {--no-warnings : Skip the expiry warnings}
        {--database= : The database connection whose package tables to sweep (default: the default connection)}';

    protected $description = 'Run due lifecycle expiries, scheduled transitions and expiry warnings';

    public function handle(LifecycleManager $lifecycle): int
    {
        try {
            $limit = IntegerOption::parse($this->option('limit'), 'limit');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $queue = $this->option('queue') === true ? true : null;
        $database = $this->option('database');
        $database = is_string($database) && $database !== '' ? $database : null;
        $result = $this->option('no-warnings') === true
            ? $lifecycle->schedules()->runDue($limit, $queue, $database)
            : $lifecycle->sweep($limit, $queue, $database);

        $this->table(['warned', 'executed', 'deferred', 'failed', 'errored', 'cancelled', 'skipped', 'queued'], [[
            $result->warned, $result->executed, $result->deferred, $result->failed,
            $result->errored, $result->cancelled, $result->skipped, $result->queued,
        ]]);

        return self::SUCCESS;
    }
}
