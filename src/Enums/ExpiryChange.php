<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

enum ExpiryChange: string
{
    use Helpers;

    case Set = 'set';
    case Extend = 'extend';
    case Renew = 'renew';
    case Clear = 'clear';
}
