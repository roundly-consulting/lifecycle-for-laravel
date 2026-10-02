<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use RoundlyConsulting\Lifecycle\Models\LifecycleState;

/**
 * A state record locked inside a mutation, and whether locking it had to create, adopt or
 * initialise anything.
 *
 * @internal
 */
final readonly class LockedRecord
{
    public function __construct(
        public LifecycleState $record,
        public bool $changed,
    ) {}
}
