<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Support;

use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\CustomSchedule;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\CustomState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\CustomTransition;
use RoundlyConsulting\Lifecycle\Tests\TestCase;

/**
 * Every package model swapped for a host subclass before the providers boot.
 */
abstract class SwappedModelsTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'lifecycle.models.state' => CustomState::class,
            'lifecycle.models.transition' => CustomTransition::class,
            'lifecycle.models.schedule' => CustomSchedule::class,
        ];
    }
}
