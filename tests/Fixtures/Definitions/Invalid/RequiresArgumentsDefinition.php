<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

final class RequiresArgumentsDefinition extends LifecycleDefinition
{
    public function __construct(private readonly string $initial) {}

    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(['a', 'b'])->initial($this->initial);
    }
}
