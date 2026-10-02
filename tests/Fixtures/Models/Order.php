<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\OrderLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\PaymentLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories\OrderFactory;

/**
 * Two lifecycles: an int-backed `status` and a plain-string `payment_status`.
 */
final class Order extends Model implements LifecycleSubject
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasLifecycle;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => OrderLifecycle::class, 'payment_status' => PaymentLifecycle::class];
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
