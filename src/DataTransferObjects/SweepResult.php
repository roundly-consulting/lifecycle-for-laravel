<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

/**
 * What one sweep did.
 */
final readonly class SweepResult
{
    public function __construct(
        public int $warned = 0,
        public int $executed = 0,
        public int $deferred = 0,
        public int $failed = 0,
        public int $errored = 0,
        public int $cancelled = 0,
        public int $skipped = 0,
        public int $queued = 0,
    ) {}

    public function total(): int
    {
        return $this->executed + $this->deferred + $this->failed + $this->errored + $this->cancelled + $this->skipped + $this->queued;
    }
}
