<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A lifecycle's freeze was lifted (`until` is the end it had, if any).
 */
final readonly class LifecycleUnfrozen implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?CarbonImmutable $until,
        public ?string $reason,
        public ?Model $actor,
    ) {}
}
