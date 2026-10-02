<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers;

use RoundlyConsulting\Lifecycle\Contracts\CompensatesTransition;
use RoundlyConsulting\Lifecycle\Contracts\TransitionHandler;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;

final class CompensatingHandler implements CompensatesTransition, TransitionHandler
{
    /** @var list<string> */
    public array $calls = [];

    public function handle(TransitionContext $context): void
    {
        $this->calls[] = 'handle:'.$context->transition->name;
    }

    public function compensate(RollbackContext $context): void
    {
        $this->calls[] = 'compensate:'.$context->reverted->transition;
    }
}
