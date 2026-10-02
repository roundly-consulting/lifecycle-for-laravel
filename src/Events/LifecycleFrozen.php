<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A lifecycle was frozen, or its freeze changed (`until` / reason).
 */
final readonly class LifecycleFrozen implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?CarbonImmutable $until,
        public ?string $reason,
        public ?Model $actor,
    ) {}
}
