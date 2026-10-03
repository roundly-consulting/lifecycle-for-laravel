<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\InlineLifecycle;

/**
 * A uuid-keyed subject with an inline lifecycle (UuidKeys tests only — its table and the
 * uuid morph columns exist there).
 */
final class UuidDocument extends Model implements LifecycleSubject
{
    use HasLifecycle;
    use HasUuids;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => InlineLifecycle::class];
    }
}
