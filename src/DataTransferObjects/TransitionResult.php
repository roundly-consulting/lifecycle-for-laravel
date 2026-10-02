<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * The outcome of an applied transition. `replayed` is true when an idempotency key matched
 * an earlier application: nothing ran again.
 */
final readonly class TransitionResult
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?string $transition,
        public BackedEnum|string|null $from,
        public BackedEnum|string $to,
        public TransitionRecord $record,
        public bool $replayed = false,
    ) {}
}
