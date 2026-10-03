<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Testing;

/**
 * What the fake records for `retrySchedule()` (and `schedules()->retry()`,
 * `for($model)->retryScheduled()`): the schedule id it was asked to retry.
 */
final readonly class RetriedSchedule
{
    public function __construct(
        public int $scheduleId,
    ) {}
}
