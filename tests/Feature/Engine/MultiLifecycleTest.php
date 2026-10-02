<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OrderStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;

it('keeps two lifecycles on one model independent', function (): void {
    $order = Order::factory()->create();

    $order->transition('authorize', lifecycle: 'payment_status');
    $order->transition('capture', lifecycle: 'payment_status');

    expect($order->status)->toBe(1)
        ->and(Lifecycles::for($order)->state())->toBe(OrderStatus::Pending)
        ->and(Lifecycles::for($order, 'payment_status')->state())->toBe('captured')
        ->and(Lifecycles::for($order)->version())->toBe(1)
        ->and(Lifecycles::for($order, 'payment_status')->version())->toBe(3)
        ->and(LifecycleState::query()->count())->toBe(2)
        ->and($order->canTransition('refund', 'payment_status'))->toBeTrue()
        ->and($order->canTransition('refund'))->toBeFalse();
});
