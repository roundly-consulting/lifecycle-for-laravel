<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use RoundlyConsulting\Lifecycle\Actions\ExampleLifecycleAction;

/**
 * REPLACE ME — the input of {@see ExampleLifecycleAction}.
 *
 * Actions take a DTO, never a shape array. Rename this alongside the action it feeds.
 */
final readonly class ExampleLifecycleData
{
    public function __construct(
        public string $name,
    ) {}
}
