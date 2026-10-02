<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

/**
 * `attempt()`: the result when the transition applied, or the decision that refused it —
 * a denial is a value here, not an exception.
 */
final readonly class TransitionAttempt
{
    public function __construct(
        public bool $succeeded,
        public ?TransitionResult $result,
        public Decision $decision,
    ) {}
}
