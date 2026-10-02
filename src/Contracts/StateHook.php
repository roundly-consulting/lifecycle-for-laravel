<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Contracts;

use RoundlyConsulting\Lifecycle\DataTransferObjects\StateHookContext;

/**
 * Runs when a transition enters (`onEnter`) or leaves (`onExit`) a state.
 */
interface StateHook
{
    public function __invoke(StateHookContext $context): void;
}
