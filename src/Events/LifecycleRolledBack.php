<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use BackedEnum;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A rollback completed (alongside one LifecycleTransitioned of kind `rollback` per step).
 */
final readonly class LifecycleRolledBack implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $revertedIds
     * @param  list<int>  $rollbackIds
     */
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public BackedEnum|string $from,
        public BackedEnum|string $to,
        public array $revertedIds,
        public array $rollbackIds,
        public ?Model $actor,
    ) {}
}
