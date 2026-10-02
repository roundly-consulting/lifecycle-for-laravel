<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;

/**
 * A scheduled transition was refused for good (`final`), or ran out of attempts.
 */
final readonly class ScheduledTransitionFailed implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<Denial>  $denials
     */
    public function __construct(
        public int $scheduleId,
        public string $subjectType,
        public int|string $subjectId,
        public string $lifecycle,
        public string $transition,
        public array $denials,
        public int $attempts,
        public bool $final,
    ) {}
}
