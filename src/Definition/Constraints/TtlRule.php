<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition\Constraints;

use Carbon\CarbonInterval;
use Closure;

/**
 * How long a subject may stay in a state before the expiry transition runs.
 */
final readonly class TtlRule
{
    /**
     * @param  list<CarbonInterval>  $leads  warning leads, largest first
     */
    public function __construct(
        public ?CarbonInterval $interval,
        public ?Closure $resolver,
        public ?string $attribute,
        public ?CarbonInterval $grace,
        public array $leads,
        public string $expiresVia,
    ) {}
}
