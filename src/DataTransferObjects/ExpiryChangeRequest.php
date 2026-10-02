<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Enums\ExpiryChange;

/**
 * Change the pending expiry of the current stay: set an instant, extend it, renew it from
 * now, or clear it. The actor is recorded for audit only.
 */
final readonly class ExpiryChangeRequest
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ExpiryChange $change,
        public ?CarbonInterface $at = null,
        public ?CarbonInterval $interval = null,
        public ?Model $actor = null,
    ) {}
}
