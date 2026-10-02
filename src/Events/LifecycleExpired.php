<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Events;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * The expiry transition ran (alongside LifecycleTransitioned of kind `expiry`).
 */
final readonly class LifecycleExpired implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public BackedEnum|string $from,
        public BackedEnum|string $to,
        public int $historyId,
        public CarbonImmutable $expiresAt,
    ) {}
}
