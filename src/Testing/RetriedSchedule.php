<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Testing;

/**
 * What the fake records for `retrySchedule()` (and `schedules()->retry()`,
 * `for($model)->retryScheduled()`): the schedule id it was asked to retry, and the database
 * connection it lives on (null = default).
 */
final readonly class RetriedSchedule
{
    public function __construct(
        public int $scheduleId,
        public ?string $connection = null,
    ) {}
}
