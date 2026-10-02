<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

/**
 * Prune history rows and finished schedule rows older than so many days; null takes the
 * configured default (`history.prune_after_days`, `schedules.prune_after_days`), where null
 * means never.
 */
final readonly class PruneOptions
{
    public function __construct(
        public ?int $historyOlderThanDays = null,
        public ?int $schedulesOlderThanDays = null,
        public bool $dryRun = false,
    ) {}
}
