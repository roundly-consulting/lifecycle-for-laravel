<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use RoundlyConsulting\Enums\Helpers;

/**
 * `Check` answers without locks or side effects (advisory); `Apply` evaluates the same rows
 * under the subject lock and consumes what must be consumed (rate-limit hits).
 *
 * @internal
 */
enum Mode: string
{
    use Helpers;

    case Check = 'check';
    case Apply = 'apply';
}
