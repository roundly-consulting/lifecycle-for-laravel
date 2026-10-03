<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;

/**
 * A pending (or finished) future system transition: a TTL expiry or a scheduled transition,
 * with the subject it belongs to and — once finished — how it ended.
 */
final readonly class ScheduledTransition
{
    public function __construct(
        public int $id,
        public string $lifecycle,
        public ScheduleKind $kind,
        public string $transition,
        public BackedEnum|string $forState,
        public CarbonImmutable $dueAt,
        public ?CarbonImmutable $expiresAt,
        public ScheduleStatus $status,
        public int $attempts,
        public ?CarbonImmutable $nextWarnAt,
        public string $subjectType,
        public int|string $subjectId,
        public ?string $lastDenial = null,
        public ?ScheduleOutcome $outcome = null,
        public ?CarbonImmutable $finishedAt = null,
    ) {}
}
