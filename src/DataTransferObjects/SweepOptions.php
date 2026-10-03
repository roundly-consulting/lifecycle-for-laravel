<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

/**
 * One sweep: at most `limit` schedules (`schedules.max_per_run` when null), inline or queued
 * (`schedules.queue.enabled` when null), with or without expiry warnings, over the package
 * tables of one database connection (the default when null — subjects on another connection
 * keep their rows there). There is no "now": a sweep always runs at the clock's now (tests
 * travel with `Carbon::setTestNow()`).
 */
final readonly class SweepOptions
{
    public function __construct(
        public ?int $limit = null,
        public ?bool $queue = null,
        public bool $warnings = true,
        public ?string $connection = null,
    ) {}
}
