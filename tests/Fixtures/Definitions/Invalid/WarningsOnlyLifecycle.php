<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * Compiles (warnings only): `orphan` is unreachable and a dead end.
 */
final class WarningsOnlyLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(['start', 'done', 'orphan'])->initial('start')->terminal('done');

        $lifecycle->transition('finish')->from('start')->to('done');
    }
}
