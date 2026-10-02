<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers;

use RoundlyConsulting\Lifecycle\Contracts\StateHook;
use RoundlyConsulting\Lifecycle\DataTransferObjects\StateHookContext;

final class RecordingHook implements StateHook
{
    /** @var list<string> */
    public array $seen = [];

    public function __invoke(StateHookContext $context): void
    {
        $this->seen[] = $context->lifecycle.':'.(is_string($context->state) ? $context->state : (string) $context->state->value);
    }
}
