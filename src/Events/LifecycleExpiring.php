<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * An expiry warning: fired once per declared lead (passed leads collapse into one, carrying
 * the most imminent).
 */
final readonly class LifecycleExpiring implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public BackedEnum|string $state,
        public CarbonImmutable $expiresAt,
        public CarbonInterval $lead,
        public int $scheduleId,
    ) {}
}
