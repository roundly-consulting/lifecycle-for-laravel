<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

final class CustomState extends LifecycleState
{
    use CountsCreations;
}
