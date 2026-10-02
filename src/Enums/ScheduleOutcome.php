<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

enum ScheduleOutcome: string
{
    use Helpers;

    case Executed = 'executed';
    case StateLeft = 'state_left';
    case Reverted = 'reverted';
    case Replaced = 'replaced';
    case Cancelled = 'cancelled';
    case SubjectMissing = 'subject_missing';
    case Denied = 'denied';
    case MaxAttempts = 'max_attempts';
    case Error = 'error';
}
