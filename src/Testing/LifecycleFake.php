<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ExampleLifecycleData;
use RoundlyConsulting\Lifecycle\LifecycleManager;

/**
 * Test double installed by `Lifecycles::fake()`. It extends the manager, so injected
 * managers keep type-checking, and it records every call instead of running the action.
 *
 * Grow it with the manager: override every mutating method (including the ones sub-accessors
 * and model traits reach), record the call, and ship an `assert<Verb>()` plus an
 * `assertNothing<Verb>()` for each.
 */
final class LifecycleFake extends LifecycleManager
{
    /** @var list<ExampleLifecycleData> */
    private array $examples = [];

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function example(ExampleLifecycleData $data): string
    {
        $this->examples[] = $data;

        // Nothing ran, so there is nothing real to return — a canned value keeps the type.
        return '';
    }

    /**
     * @param  (Closure(ExampleLifecycleData): bool)|null  $callback
     */
    public function assertExampleCalled(?Closure $callback = null): void
    {
        $matching = array_filter(
            $this->examples,
            static fn (ExampleLifecycleData $data): bool => $callback === null || $callback($data),
        );

        PHPUnit::assertNotEmpty($matching, $callback === null
            ? 'Expected example() to be called, but it was not.'
            : 'Expected example() to be called with matching data, but no call matched.');
    }

    public function assertNothingCalled(): void
    {
        PHPUnit::assertEmpty(
            $this->examples,
            sprintf('Expected nothing to be called, but example() was called %d time(s).', count($this->examples)),
        );
    }
}
