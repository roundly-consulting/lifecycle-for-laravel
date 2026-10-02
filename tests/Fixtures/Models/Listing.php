<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories\ListingFactory;

/**
 * A string-enum subject with soft deletes, an enum cast and a stamp column.
 */
class Listing extends Model implements LifecycleSubject
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    use HasLifecycle;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, class-string<ListingLifecycle>>
     */
    public function lifecycleDefinitions(): array
    {
        return ['status' => ListingLifecycle::class];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ListingFactory
    {
        return ListingFactory::new();
    }
}
