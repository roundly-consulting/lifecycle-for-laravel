<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Teams\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RoundlyConsulting\Teams\Facades\Teams;

/**
 * Records the team context a queued job runs in.
 */
final class RecordTeamJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly string $what,
        public readonly int $ticket,
    ) {}

    public function handle(): void
    {
        TeamTicketLifecycle::$seen[] = ['job:'.$this->what, $this->ticket, Teams::current()?->getKey()];
    }
}
