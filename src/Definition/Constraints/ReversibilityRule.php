<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition\Constraints;

use Carbon\CarbonInterval;
use RoundlyConsulting\Lifecycle\Contracts\Guard;

/**
 * Whether and for how long a transition can be rolled back, and who may do it.
 */
final readonly class ReversibilityRule
{
    /**
     * @param  list<Guard|class-string<Guard>>  $guards
     */
    public function __construct(
        public bool $irreversible = false,
        public ?CarbonInterval $window = null,
        public bool $withoutCompensation = false,
        public bool $compensable = true,
        public ?string $ability = null,
        public array $guards = [],
    ) {}

    /**
     * Reversible unless declared irreversible — and a handler that cannot compensate makes it
     * irreversible unless the transition opts in with `reversible(withoutCompensation: true)`.
     */
    public function isReversible(): bool
    {
        return ! $this->irreversible && ($this->compensable || $this->withoutCompensation);
    }

    public function hasActorRules(): bool
    {
        return $this->ability !== null || $this->guards !== [];
    }
}
