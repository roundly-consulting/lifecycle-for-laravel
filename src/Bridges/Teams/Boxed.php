<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Bridges\Teams;

/**
 * Carries a callback's result through `Teams::runAs()` untouched.
 *
 * @internal
 *
 * @template T
 */
final readonly class Boxed
{
    /**
     * @param  T  $value
     */
    public function __construct(
        public mixed $value,
    ) {}
}
