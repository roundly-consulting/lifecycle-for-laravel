<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * What an `onEnter` / `onExit` hook sees.
 */
final readonly class StateHookContext
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public BackedEnum|string $state,
        public TransitionContext $transition,
    ) {}
}
