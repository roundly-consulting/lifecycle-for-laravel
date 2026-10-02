<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;

final class NoLifecycles extends Model implements LifecycleSubject
{
    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return [];
    }
}
