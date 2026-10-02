<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ExampleLifecycleData;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Testing\LifecycleFake;

/**
 * Keep one `@method static` line per public manager method — FacadeTest's
 * `toDocumentItsRoot()` fails on a missing, stale or miscounted line.
 *
 * @method static string example(ExampleLifecycleData $data)
 * @method static void assertExampleCalled(\Closure|null $callback = null)
 * @method static void assertNothingCalled()
 *
 * @see LifecycleManager
 */
final class Lifecycles extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected LifecycleManager is faked too.
     */
    public static function fake(): LifecycleFake
    {
        $fake = app(LifecycleFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return LifecycleManager::class;
    }
}
