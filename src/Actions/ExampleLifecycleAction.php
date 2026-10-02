<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\ExampleLifecycleData;

/**
 * REPLACE ME — a placeholder action showing the shape every use case takes.
 *
 * `final readonly`, dependencies through the constructor, one public `execute()` that takes
 * a DTO. This is where the behaviour lives: the manager only resolves it through the
 * container and calls `execute()`. Rename it to the package's first real verb
 * (`GrantCreditsAction`, `AddMemberAction`, …), rename the manager method and the fake's
 * recorder with it, and delete this example once a real action exists.
 *
 * A building block only other actions call carries `@internal` in this docblock and is not
 * exposed on the facade — `toReachEveryAction()` in FacadeTest enforces the difference.
 */
final readonly class ExampleLifecycleAction
{
    public function execute(ExampleLifecycleData $data): string
    {
        return 'Hello, '.$data->name.'!';
    }
}
