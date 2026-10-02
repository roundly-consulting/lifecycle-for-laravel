<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Freeze one lifecycle of a subject (until an instant, or until unfrozen). The actor is
 * recorded for audit only — who may freeze is the host's policy.
 */
final readonly class FreezeRequest
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?CarbonInterface $until = null,
        public ?string $reason = null,
        public ?Model $actor = null,
    ) {}
}
