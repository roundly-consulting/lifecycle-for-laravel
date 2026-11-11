<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Bridges\Teams;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\RestoresSubjectContext;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectContextUnavailableException;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Teams\Support\TeamModel;
use RoundlyConsulting\Teams\TeamsManager;
use RoundlyConsulting\Teams\Traits\ScopedToTeam;

/**
 * teams-for-laravel, when installed: a `ScopedToTeam` subject is acted on inside
 * `Teams::runAs()` of its own team — the scope's write guard refuses a write without one, and
 * a console sweep or a queued job has none (or another team's). `runAs()` restores the previous
 * context, also on an exception, and jobs dispatched inside carry the team along. A subject
 * whose team was deleted (or never existed) throws SubjectContextUnavailableException. Every
 * other subject runs as it is.
 *
 * @internal
 */
final readonly class TeamsSubjectContext implements RestoresSubjectContext
{
    public function __construct(
        private Container $container,
    ) {}

    public function run(Model $subject, Closure $callback): mixed
    {
        if (! in_array(ScopedToTeam::class, class_uses_recursive($subject), true) || ! method_exists($subject, 'getTeamColumn')) {
            return $callback();
        }

        $key = $subject->getAttribute($subject->getTeamColumn());
        $team = is_int($key) || is_string($key) ? TeamModel::query()->find($key) : null;

        if (! $team instanceof Team) {
            throw SubjectContextUnavailableException::teamMissing($subject, $key);
        }

        // Boxed: runAs() swallows a PendingDispatch result (it dispatches it inside the context).
        return $this->container->make(TeamsManager::class)
            ->runAs($team, static fn (): Boxed => new Boxed($callback()))
            ->value;
    }
}
