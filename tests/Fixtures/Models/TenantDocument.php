<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\InlineLifecycle;

/**
 * A document behind a tenant global scope that hides everything: quotas must count without it.
 */
final class TenantDocument extends Model implements LifecycleSubject
{
    use HasLifecycle;
    use SoftDeletes;

    protected $table = 'documents';

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => InlineLifecycle::class];
    }

    protected static function booted(): void
    {
        self::addGlobalScope('tenant', static fn (Builder $query) => $query->where('documents.title', 'never matches'));
    }
}
