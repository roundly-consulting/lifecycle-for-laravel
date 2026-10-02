<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Dispatched synchronously inside the transaction, right before the state is written. Not
 * vetoable: a throwing listener aborts and rolls back; refusals belong in guards, so that
 * `check()` and `apply()` agree. A deadlock retry dispatches it again.
 */
final readonly class LifecycleTransitioning
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public string $transition,
        public BackedEnum|string|null $from,
        public BackedEnum|string $to,
        public ?Model $actor,
        public bool $system,
    ) {}
}
