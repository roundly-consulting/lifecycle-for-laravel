<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OrderStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\CastOrder;

it('reads and writes lifecycle attributes as states', function (): void {
    $order = CastOrder::query()->create();

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->payment_status)->toBe('unpaid');

    $order->transition('pay');

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and(CastOrder::query()->toBase()->value('status'))->toEqual(2)
        ->and($order->fresh()?->status)->toBe(OrderStatus::Paid);
});

it('stores declared states only, as raw values', function (): void {
    $order = new CastOrder;
    $order->status = OrderStatus::Fulfilled;
    $order->payment_status = 'captured';

    expect($order->getAttributes())->toBe(['status' => 3, 'payment_status' => 'captured']);

    $order->status = null;

    expect($order->getAttributes()['status'])->toBeNull()
        ->and(fn () => $order->payment_status = 'stolen')->toThrow(UnknownStateException::class);
});
