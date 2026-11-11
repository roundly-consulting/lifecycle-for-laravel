<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Teams\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Teams\Traits\ScopedToTeam;

/**
 * A support ticket isolated per team — the usual multi-tenant subject.
 *
 * @property int $id
 * @property int|null $team_id
 * @property string|null $status
 */
final class TeamTicket extends Model implements LifecycleSubject
{
    use HasLifecycle;
    use ScopedToTeam;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['status' => TeamTicketLifecycle::class];
    }
}
