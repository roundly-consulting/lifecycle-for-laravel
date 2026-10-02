<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OrderStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Ticket;

it('round-trips every state type through history and the handle', function (Closure $make, string $transition, mixed $expected, string $key, string $lifecycle): void {
    $subject = $make();
    $result = Lifecycles::for($subject, $lifecycle)->apply($transition);
    $row = LifecycleTransition::query()->whereKey($result->record->id)->sole();

    expect($result->to)->toBe($expected)
        ->and($row->to_state)->toBe($key)
        ->and($row->toRecord(Lifecycles::for($subject, $lifecycle)->definition())->to)->toBe($expected)
        ->and(Lifecycles::for($subject->fresh(), $lifecycle)->state())->toBe($expected)
        ->and($subject::query()->whereState($expected, $lifecycle)->count())->toBe(1)
        ->and($subject::query()->whereNotState([$expected], $lifecycle)->count())->toBe(0);
})->with([
    'string enum' => [fn () => Listing::factory()->create(), 'publish', ListingStatus::Active, 'active', 'status'],
    'int enum' => [fn () => Order::factory()->create(), 'pay', OrderStatus::Paid, '2', 'status'],
    'plain strings' => [fn () => Ticket::factory()->create(), 'open', 'open', 'open', 'status'],
    'second lifecycle' => [fn () => Order::factory()->create(), 'authorize', 'authorized', 'authorized', 'payment_status'],
]);

it('stores the int backing value in the host column', function (): void {
    $order = Order::factory()->create();
    $order->transition('pay');

    expect(Order::query()->toBase()->value('status'))->toEqual(2)
        ->and($order->paid_at)->not->toBeNull();
});
