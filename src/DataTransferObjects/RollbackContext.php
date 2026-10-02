<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a compensating handler sees while one history row is reverted.
 */
final readonly class RollbackContext
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public TransitionRecord $reverted,
        public BackedEnum|string $from,
        public BackedEnum|string $to,
        public ?Model $actor,
        public ?string $reason,
        public CarbonImmutable $now,
    ) {}
}
