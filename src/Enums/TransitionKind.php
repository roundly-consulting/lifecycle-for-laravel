<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * The kind of a history row. Frozen from 1.0.0.
 */
enum TransitionKind: string
{
    use Helpers;

    case Initial = 'initial';
    case Transition = 'transition';
    case Expiry = 'expiry';
    case Scheduled = 'scheduled';
    case Rollback = 'rollback';
    case Adopted = 'adopted';

    /**
     * Rows that form the undo stack (when no rollback row reverts them).
     */
    public function isOnEffectivePath(): bool
    {
        return $this !== self::Rollback;
    }

    /**
     * Rows a rollback may revert. Expiry rows are not reversible: un-expiring is a forward
     * transition with its own guards and a fresh TTL.
     */
    public function isReversibleKind(): bool
    {
        return $this === self::Transition || $this === self::Scheduled;
    }
}
