<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

final class CustomTransition extends LifecycleTransition
{
    use CountsCreations;
}
