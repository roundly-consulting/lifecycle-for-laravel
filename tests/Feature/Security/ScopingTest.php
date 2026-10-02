<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\DataTransferObjects\CancelScheduleRequest;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;

/**
 * IDOR: history ids and schedules of another subject or lifecycle are never reachable.
 */
it('refuses rolling back to another subject history row', function (): void {
    $mine = Listing::factory()->create();
    $theirs = Listing::factory()->create();
    $theirs->transition('publish');
    $mine->transition('publish');

    Lifecycles::for($mine)->rollbackTo(LifecycleTransition::query()->where('subject_id', $theirs->id)->where('kind', 'initial')->sole()->id);
})->throws(RollbackDeniedException::class, 'cannot be rolled back to');

it('refuses rolling back to another lifecycle history row of the same subject', function (): void {
    $order = Order::factory()->create();
    $order->transition('authorize', lifecycle: 'payment_status');
    $order->transition('pay');
    $paymentInitial = LifecycleTransition::query()->where('lifecycle', 'payment_status')->where('kind', 'initial')->sole()->id;

    Lifecycles::for($order)->rollbackTo($paymentInitial);
})->throws(RollbackDeniedException::class, 'cannot be rolled back to');

it('cancels only the subject own schedules', function (): void {
    $mine = Listing::factory()->create();
    $theirs = Listing::factory()->create();
    $theirs->transition('publish');

    expect(Lifecycles::cancelScheduled(new CancelScheduleRequest($mine, 'status', '@expiry')))->toBeFalse()
        ->and($theirs->lifecycleSchedules()->where('status', 'pending')->count())->toBe(1);
});
