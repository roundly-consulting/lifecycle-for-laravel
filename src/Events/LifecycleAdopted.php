<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use BackedEnum;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * The stored state changed outside the engine (a query-builder update, raw SQL, a missing
 * state record) and the engine adopted it. `recordedState` is the state key the record held,
 * null when there was no record.
 */
final readonly class LifecycleAdopted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?string $recordedState,
        public BackedEnum|string $actualState,
        public int $historyId,
    ) {}
}
