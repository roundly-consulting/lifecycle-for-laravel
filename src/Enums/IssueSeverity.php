<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

enum IssueSeverity: string
{
    use Helpers;

    case Error = 'error';
    case Warning = 'warning';
}
