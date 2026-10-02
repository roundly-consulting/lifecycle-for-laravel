<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\InlineLifecycle;

/**
 * A subject living on a second connection: package rows must follow it there.
 */
final class SecondaryDocument extends Model implements LifecycleSubject
{
    use HasLifecycle;

    protected $connection = 'secondary';

    protected $table = 'documents';

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => InlineLifecycle::class];
    }
}
