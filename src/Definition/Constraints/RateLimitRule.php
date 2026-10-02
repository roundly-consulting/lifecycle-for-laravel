<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition\Constraints;

use Carbon\CarbonInterval;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;

/**
 * At most `maxAttempts` applications of a transition per `decay`, counted per actor,
 * per subject, or per both.
 */
final readonly class RateLimitRule
{
    public function __construct(
        public int $maxAttempts,
        public CarbonInterval $decay,
        public RateLimitScope $per,
    ) {}
}
