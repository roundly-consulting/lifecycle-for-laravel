<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;

/**
 * One row of a subject's lifecycle history.
 */
final readonly class TransitionRecord
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $snapshot
     */
    public function __construct(
        public int $id,
        public string $lifecycle,
        public TransitionKind $kind,
        public ?string $transition,
        public BackedEnum|string|null $from,
        public BackedEnum|string $to,
        public ?string $actorType,
        public int|string|null $actorId,
        public bool $system,
        public ?string $reason,
        public array $context,
        public ?array $snapshot,
        public int $version,
        public ?int $revertsId,
        public ?int $scheduleId,
        public CarbonImmutable $occurredAt,
        public bool $reverted,
    ) {}
}
