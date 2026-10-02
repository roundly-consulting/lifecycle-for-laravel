<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\PaymentLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories\PlainModelFactory;

/**
 * No SoftDeletes and no timestamps: the trait must boot and transitions must write nothing
 * but the state.
 */
final class PlainModel extends Model implements LifecycleSubject
{
    /** @use HasFactory<PlainModelFactory> */
    use HasFactory;

    use HasLifecycle;

    public $timestamps = false;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => PaymentLifecycle::class];
    }

    protected static function newFactory(): PlainModelFactory
    {
        return PlainModelFactory::new();
    }
}
