<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;

/**
 * Keeps the schedule rows of a subject in step with its state: entering a state schedules
 * its expiry, leaving it cancels what was bound to it. Every method runs with the subject
 * row already locked by its caller.
 *
 * @internal
 */
final readonly class ScheduleBook
{
    public function enter(Model $subject, string $lifecycle, CompiledDefinition $definition, string $state, int $historyId, CarbonImmutable $now): void
    {
        // Expiry scheduling lands with the schedules engine.
    }

    public function leave(Model $subject, string $lifecycle, string $state, int $historyId, CarbonImmutable $now): void
    {
        // Cancelling bound schedules lands with the schedules engine.
    }
}
