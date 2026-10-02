<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

/**
 * Rows deleted (or, for a dry run, that would be).
 */
final readonly class PruneResult
{
    public function __construct(
        public int $historyDeleted = 0,
        public int $schedulesDeleted = 0,
    ) {}
}
