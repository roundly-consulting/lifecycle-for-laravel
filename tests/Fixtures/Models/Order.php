<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\OrderLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\PaymentLifecycle;

final class Order extends Model implements LifecycleSubject
{
    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => OrderLifecycle::class, 'payment_status' => PaymentLifecycle::class];
    }
}
