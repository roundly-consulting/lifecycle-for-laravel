<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition\Constraints;

use Carbon\CarbonInterval;
use Closure;

/**
 * A `notBefore` / `notAfter` limit: an attribute name or a closure returning the instant,
 * shifted by an optional signed offset. A null instant never blocks.
 */
final readonly class DeadlineRule
{
    public function __construct(
        public Closure|string $source,
        public ?CarbonInterval $offset,
    ) {}
}
