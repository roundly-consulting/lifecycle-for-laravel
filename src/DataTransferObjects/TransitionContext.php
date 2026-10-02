<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\TransitionDefinition;

/**
 * What guards, handlers and hooks see about the transition being checked or applied.
 */
final readonly class TransitionContext
{
    /**
     * @param  array<string, mixed>  $payload  the validated payload
     */
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public TransitionDefinition $transition,
        public BackedEnum|string|null $from,
        public BackedEnum|string $to,
        public ?Model $actor,
        public bool $system,
        public ?string $reason,
        public array $payload,
        public CarbonImmutable $now,
        public int $version,
        public ?int $scheduleId = null,
    ) {}
}
