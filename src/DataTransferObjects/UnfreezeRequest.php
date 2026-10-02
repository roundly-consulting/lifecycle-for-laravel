<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * Lift the freeze of one lifecycle. The actor is recorded for audit only.
 */
final readonly class UnfreezeRequest
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?string $reason = null,
        public ?Model $actor = null,
    ) {}
}
