<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Enums;

use RoundlyConsulting\Enums\Helpers;

enum GraphFormat: string
{
    use Helpers;

    case Mermaid = 'mermaid';
    case Dot = 'dot';
}
