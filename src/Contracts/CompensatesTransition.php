<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Contracts;

use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackContext;

/**
 * Undoes a handler's side effects, inside the rollback transaction.
 */
interface CompensatesTransition
{
    public function compensate(RollbackContext $context): void;
}
