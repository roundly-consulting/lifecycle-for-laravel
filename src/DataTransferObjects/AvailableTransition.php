<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Carbon\CarbonImmutable;

/**
 * A transition leaving the current state, as a UI renders it: allowed or not (with the
 * reasons), and what it asks for — a reason, payload fields.
 */
final readonly class AvailableTransition
{
    /**
     * @param  list<Denial>  $denials
     * @param  list<string>  $payloadFields
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $name,
        public string $label,
        public BackedEnum|string $to,
        public string $toLabel,
        public bool $allowed,
        public array $denials,
        public bool $requiresReason,
        public array $payloadFields,
        public ?CarbonImmutable $availableAt,
        public array $meta,
    ) {}
}
