<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Readme;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;

/**
 * The README's quick-start model, verbatim (on the fixture `listings` table).
 */
final class Listing extends Model implements LifecycleSubject
{
    use HasLifecycle;
    use SoftDeletes;

    protected $table = 'listings';

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => ListingLifecycle::class];
    }

    protected function casts(): array
    {
        return ['status' => ListingStatus::class, 'published_at' => 'datetime'];
    }
}
