<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;

/**
 * The rows a rollback would revert (newest first), whether it may, and the state it ends in.
 *
 * @internal
 */
final readonly class RollbackPlan
{
    /**
     * @param  list<LifecycleTransition>  $targets
     */
    public function __construct(
        public array $targets,
        public Decision $decision,
        public ?string $finalState,
    ) {}
}
