<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\TicketLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories\TicketFactory;

final class Ticket extends Model implements LifecycleSubject
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasLifecycle;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => TicketLifecycle::class];
    }

    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }
}
