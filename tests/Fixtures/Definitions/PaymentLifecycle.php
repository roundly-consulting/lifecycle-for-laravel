<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * A plain-string lifecycle, the second one on orders.
 */
final class PaymentLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(['unpaid', 'authorized', 'captured', 'refunded'])
            ->initial('unpaid')
            ->terminal('refunded');

        $lifecycle->transition('authorize')->from('unpaid')->to('authorized');
        $lifecycle->transition('capture')->from('authorized')->to('captured');
        $lifecycle->transition('refund')->from('captured')->to('refunded');
    }
}
