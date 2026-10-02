<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

enum RateLimitScope: string
{
    use Helpers;

    case Actor = 'actor';
    case Subject = 'subject';
    case ActorAndSubject = 'actor_and_subject';
}
