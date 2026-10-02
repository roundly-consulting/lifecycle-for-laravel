<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\Engine\TransitionExecutor;

/**
 * Applies a transition, or throws TransitionDeniedException with every reason it was refused.
 */
final readonly class ApplyTransitionAction
{
    public function __construct(
        private TransitionExecutor $executor,
    ) {}

    public function execute(TransitionRequest $request): TransitionResult
    {
        return $this->executor->apply($request);
    }
}
