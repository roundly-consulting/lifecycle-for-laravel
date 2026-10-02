<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use BackedEnum;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;

/**
 * Fired after commit once per history row of every state change — transitions, expiries,
 * scheduled transitions, rollback steps and adoptions. Listen to this one event instead of
 * model events: the state write is a compare-and-swap that fires no `updated` event. Queued
 * listeners should re-fetch the subject by `subjectType` / `subjectId`.
 */
final readonly class LifecycleTransitioned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $subject,
        public string $subjectType,
        public int|string $subjectId,
        public string $lifecycle,
        public TransitionKind $kind,
        public ?string $transition,
        public BackedEnum|string|null $from,
        public BackedEnum|string $to,
        public ?Model $actor,
        public bool $system,
        public int $historyId,
        public int $version,
    ) {}
}
