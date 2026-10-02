<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * Two errors (a missing initial state and an unknown target) and one warning.
 */
final class BrokenLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(['a', 'b', 'c']);

        $lifecycle->transition('go')->from('a')->to('z');
    }
}
