<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;

final class Listing extends Model implements LifecycleSubject
{
    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => ListingLifecycle::class];
    }
}
