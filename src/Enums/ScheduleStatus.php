<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

enum ScheduleStatus: string
{
    use Helpers;

    case Pending = 'pending';
    case Paused = 'paused';
    case Executed = 'executed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /**
     * Open schedules still hold their slot; finished ones release it.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Paused;
    }
}
