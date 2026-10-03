<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\InlineLifecycle;

/**
 * Two lifecycles with the same (inline) definition — so the same transition names — on one model.
 */
final class DualDocument extends Model implements LifecycleSubject
{
    use HasLifecycle;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => InlineLifecycle::class, 'review_status' => InlineLifecycle::class];
    }
}
