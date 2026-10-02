<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

enum ScheduleKind: string
{
    use Helpers;

    case Expiry = 'expiry';
    case Transition = 'transition';
}
