<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\TicketLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories\UuidListingFactory;

final class UuidListing extends Model implements LifecycleSubject
{
    /** @use HasFactory<UuidListingFactory> */
    use HasFactory;

    use HasLifecycle;
    use HasUuids;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => TicketLifecycle::class];
    }

    protected static function newFactory(): UuidListingFactory
    {
        return UuidListingFactory::new();
    }
}
