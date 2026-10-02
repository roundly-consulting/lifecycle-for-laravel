<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;

/**
 * The outcome of one warning pass over a schedule: how many leads are spent, when the next
 * one is due, and the lead to announce now (null = nothing to fire).
 *
 * @internal
 */
final readonly class WarningStep
{
    public function __construct(
        public int $sent,
        public ?CarbonImmutable $next,
        public ?CarbonInterval $fire,
    ) {}
}
