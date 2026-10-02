<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers;

use RoundlyConsulting\Lifecycle\Contracts\TransitionHandler;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;

/**
 * A handler that cannot compensate: its transition is irreversible unless it opts in.
 */
final class PlainHandler implements TransitionHandler
{
    /** @var list<string> */
    public array $handled = [];

    public function handle(TransitionContext $context): void
    {
        $this->handled[] = $context->transition->name;
    }
}
