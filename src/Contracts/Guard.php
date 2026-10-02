<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Contracts;

use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;

/**
 * A custom check in the guard pipeline. Return null to allow, a Denial to refuse.
 */
interface Guard
{
    public function check(TransitionContext $context): ?Denial;
}
