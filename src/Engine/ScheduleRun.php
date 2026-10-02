<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use RoundlyConsulting\Enums\Helpers;

/**
 * What executing one due schedule did — the sweep's counters.
 *
 * @internal
 */
enum ScheduleRun: string
{
    use Helpers;

    case Executed = 'executed';
    case Deferred = 'deferred';
    case Failed = 'failed';
    case Errored = 'errored';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
}
