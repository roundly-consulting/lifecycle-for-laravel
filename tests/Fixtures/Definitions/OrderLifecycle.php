<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OrderStatus;

/**
 * An int-backed lifecycle: keys are stored as '1', '2', … and decoded back to the cases.
 */
final class OrderLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(OrderStatus::class)
            ->initial(OrderStatus::Pending)
            ->terminal(OrderStatus::Fulfilled, OrderStatus::Cancelled);

        $lifecycle->state(OrderStatus::Paid)->stamps('paid_at');

        $lifecycle->transition('pay')->from(OrderStatus::Pending)->to(OrderStatus::Paid);
        $lifecycle->transition('fulfil')->from(OrderStatus::Paid)->to(OrderStatus::Fulfilled);
        $lifecycle->transition('cancel')->fromAnyExcept(OrderStatus::Paid)->to(OrderStatus::Cancelled);
    }
}
