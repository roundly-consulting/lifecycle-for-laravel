<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Lifecycle\Actions\RunScheduledTransitionAction;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Runs one due schedule on a queue (queued sweeps). One try: the action does its own
 * attempt accounting, so a failure is retried by a later sweep, never re-dispatched in a loop.
 * The unique lock is taken by the sweep itself before dispatching.
 *
 * @internal
 */
final class RunScheduledTransitionJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    /**
     * @param  string|null  $database  the database connection holding the schedule row (null = default)
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly ?string $database = null,
    ) {
        $connection = config('lifecycle.schedules.queue.connection');
        $queue = config('lifecycle.schedules.queue.name');

        $this->onConnection(is_string($connection) && $connection !== '' ? $connection : null);
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : null);
    }

    public function uniqueId(): string
    {
        return $this->database === null ? (string) $this->scheduleId : $this->database.':'.$this->scheduleId;
    }

    public function handle(RunScheduledTransitionAction $action): void
    {
        $action->execute($this->scheduleId, Clock::now(), $this->database);
    }
}
