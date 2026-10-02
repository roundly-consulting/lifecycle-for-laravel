<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Guards;

use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;

/**
 * Denies when the subject's title is "blocked"; counts its own evaluations.
 */
final class DenyingGuard implements Guard
{
    public int $calls = 0;

    public function check(TransitionContext $context): ?Denial
    {
        $this->calls++;

        return $context->subject->getAttribute('title') === 'blocked'
            ? Denial::of('blocked_title', ['title' => 'blocked'], 'The title is blocked.')
            : null;
    }
}
