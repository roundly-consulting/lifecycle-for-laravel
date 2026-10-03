<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;

/**
 * Whether the host scheduled `lifecycle:sweep` — so `about` and `lifecycle:validate` can say so
 * instead of expiries and scheduled transitions silently never running. Only the console
 * knows the schedule: anywhere else the answer is unknown (null).
 *
 * @internal
 */
final class SweepSchedule
{
    /**
     * Whether a sweep of `$connection`'s package tables is scheduled (null = the default
     * connection): `lifecycle:sweep`, with `--database=<connection>` for any other one.
     */
    public static function isScheduled(?string $connection = null): ?bool
    {
        $container = Container::getInstance();

        if (! $container instanceof Application || ! $container->runningInConsole() || ! $container->bound(Schedule::class)) {
            return null;
        }

        $wanted = self::connection($connection);

        foreach ($container->make(Schedule::class)->events() as $event) {
            if (self::sweeps($event) && self::connection(self::database($event)) === $wanted) {
                return true;
            }
        }

        return false;
    }

    private static function sweeps(Event $event): bool
    {
        return is_string($event->command) && str_contains($event->command, 'lifecycle:sweep');
    }

    /**
     * The `--database` a scheduled sweep names, if any.
     */
    private static function database(Event $event): ?string
    {
        return preg_match('/--database[= ]+[\'"]?([^\s\'"]+)/', (string) $event->command, $match) === 1 ? $match[1] : null;
    }

    /**
     * A connection name, with the default connection (null or its own name) as ''.
     */
    private static function connection(?string $name): string
    {
        $default = config('database.default');

        return $name === null || $name === $default ? '' : $name;
    }
}
