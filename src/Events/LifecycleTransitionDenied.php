<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;

/**
 * Fired immediately after a denied `apply()` rolled back — deliberately not after commit, so
 * it still reports the attempt when the host's own transaction is rolled back because of
 * the denial.
 */
final readonly class LifecycleTransitionDenied
{
    /**
     * @param  list<Denial>  $denials
     */
    public function __construct(
        public string $subjectType,
        public int|string $subjectId,
        public string $lifecycle,
        public ?string $transition,
        public ?Model $actor,
        public array $denials,
    ) {}
}
