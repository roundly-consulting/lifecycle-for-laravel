<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Contracts;

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;

/**
 * Side effects of a transition, run inside its transaction after the state was written.
 * Implement CompensatesTransition too, or the transition becomes irreversible.
 */
interface TransitionHandler
{
    public function handle(TransitionContext $context): void;
}
