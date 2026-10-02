<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Casts\AsLifecycleState;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\OrderLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\PaymentLifecycle;

/**
 * The orders table with both lifecycle attributes cast by AsLifecycleState.
 */
final class CastOrder extends Model implements LifecycleSubject
{
    use HasLifecycle;

    protected $table = 'orders';

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => OrderLifecycle::class, 'payment_status' => PaymentLifecycle::class];
    }

    protected function casts(): array
    {
        return ['status' => AsLifecycleState::class, 'payment_status' => AsLifecycleState::class];
    }
}
