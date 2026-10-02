<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Testing;

use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;

/**
 * One call the fake recorded: the manager method, its request, what it returned, and the
 * decision when it was denied.
 */
final readonly class RecordedCall
{
    public function __construct(
        public string $method,
        public object $request,
        public mixed $result = null,
        public ?Decision $denied = null,
    ) {}
}
