<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Lifecycle\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\SweepResult;
use RoundlyConsulting\Lifecycle\Engine\ScheduleRun;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownLifecycleException;
use RoundlyConsulting\Lifecycle\Jobs\RunScheduledTransitionJob;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\SoftDeletion;
use RoundlyConsulting\Lifecycle\Support\SubjectResolver;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The sweep: paused rows of subjects restored without model events resumed, expiry warnings
 * (optional), then every due schedule in keyset batches by `(due_at, id)` — one transaction per schedule, inline or as a queued job. Bounded by
 * `schedules.max_per_run` (or the given limit). Always at the clock's now.
 */
final readonly class RunDueSchedulesAction
{
    public function __construct(
        private RunScheduledTransitionAction $run,
        private SendExpiryWarningsAction $warnings,
        private Bus $bus,
        private Cache $cache,
    ) {}

    public function execute(SweepOptions $options): SweepResult
    {
        $config = Config::using(InvalidLifecycleConfigurationException::class);
        $limit = $options->limit ?? $config->integer('lifecycle.schedules.max_per_run', 10000, 1, 1000000);
        $batch = $config->integer('lifecycle.schedules.batch_size', 500, 1, 10000);
        $queue = $options->queue ?? Config::boolean('lifecycle.schedules.queue.enabled');
        $this->resumeRestored($options->connection);
        $warned = $options->warnings ? $this->warnings->execute($options) : 0;

        $now = Clock::now();
        $counts = array_fill_keys([...ScheduleRun::values()->all(), 'queued'], 0);
        $cursor = null;
        $done = 0;

        while ($done < $limit) {
            $query = ScheduleModel::query($options->connection)
                ->where('status', ScheduleStatus::Pending->value)
                ->where('due_at', '<=', Clock::format($now))
                ->orderBy('due_at')
                ->orderBy('id')
                ->limit(min($batch, $limit - $done));

            if ($cursor !== null) {
                [$dueAt, $id] = $cursor;
                $query->where(static fn ($keyset) => $keyset->where('due_at', '>', $dueAt)
                    ->orWhere(static fn ($tie) => $tie->where('due_at', $dueAt)->where('id', '>', $id)));
            }

            $rows = $query->get(['id', 'due_at']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                if ($queue) {
                    $job = new RunScheduledTransitionJob($row->id, $options->connection);

                    // ShouldBeUnique is only enforced by Foundation's PendingDispatch, which a
                    // dispatch through the Bus contract never reaches: take the lock here.
                    if ((new UniqueLock($this->cache))->acquire($job)) {
                        $this->bus->dispatch($job);
                        $counts['queued']++;
                    } else {
                        $counts[ScheduleRun::Skipped->value]++;
                    }

                    continue;
                }

                $counts[$this->run->execute($row->id, $now, $options->connection)->value]++;
            }

            $last = $rows->last();
            $cursor = [Clock::format($last->due_at), $last->id];
            $done += $rows->count();
        }

        return new SweepResult(
            warned: $warned,
            executed: $counts[ScheduleRun::Executed->value],
            deferred: $counts[ScheduleRun::Deferred->value],
            failed: $counts[ScheduleRun::Failed->value],
            errored: $counts[ScheduleRun::Errored->value],
            cancelled: $counts[ScheduleRun::Cancelled->value],
            skipped: $counts[ScheduleRun::Skipped->value],
            queued: $counts['queued'],
        );
    }

    /**
     * Rows stay `paused` while their subject is soft-deleted; the `restored` model event resumes
     * them. A restore that fires no event (`restoreQuietly()`, a query-builder `restore()`) is
     * caught here: paused rows whose subject row exists and is not trashed are pending again.
     */
    private function resumeRestored(?string $connection): void
    {
        $types = ScheduleModel::query($connection)->where('status', ScheduleStatus::Paused->value)->distinct()->pluck('subject_type')->all();

        foreach ($types as $type) {
            try {
                $class = SubjectResolver::classFor((string) $type);
            } catch (UnknownLifecycleException) {
                continue;
            }

            $subject = new $class;
            $rows = ScheduleModel::query($connection)->where('status', ScheduleStatus::Paused->value)->where('subject_type', $type);
            $table = $rows->getModel()->getTable();

            $rows->whereExists(static function (QueryBuilder $live) use ($subject, $table): void {
                $live->selectRaw('1')
                    ->from($subject->getTable())
                    ->whereColumn($subject->getQualifiedKeyName(), $table.'.subject_id');

                if (SoftDeletion::uses($subject)) {
                    $live->whereNull(SoftDeletion::qualifiedColumn($subject));
                }
            });

            $rows->toBase()->update([
                'status' => ScheduleStatus::Pending->value,
                'updated_at' => $rows->getModel()->freshTimestampString(),
            ]);
        }
    }
}
