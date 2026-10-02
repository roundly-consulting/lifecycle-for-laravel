<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

final class CustomSchedule extends LifecycleSchedule
{
    use CountsCreations;
}
