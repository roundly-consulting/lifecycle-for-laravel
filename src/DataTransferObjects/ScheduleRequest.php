<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Run a transition later, as the system. The scheduling actor's rules are checked now; time
 * rows, quotas and rate limits when it runs. `system` is the handle's `asSystem()` — needed
 * to schedule a `systemOnly()` transition.
 */
final readonly class ScheduleRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public string $transition,
        public CarbonInterface $at,
        public ?Model $actor = null,
        public bool $system = false,
        public ?string $reason = null,
        public array $payload = [],
    ) {}
}
