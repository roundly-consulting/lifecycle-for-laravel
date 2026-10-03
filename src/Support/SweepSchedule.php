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
    public static function isScheduled(): ?bool
    {
        $container = Container::getInstance();

        if (! $container instanceof Application || ! $container->runningInConsole() || ! $container->bound(Schedule::class)) {
            return null;
        }

        foreach ($container->make(Schedule::class)->events() as $event) {
            if (self::sweeps($event)) {
                return true;
            }
        }

        return false;
    }

    private static function sweeps(Event $event): bool
    {
        return is_string($event->command) && str_contains($event->command, 'lifecycle:sweep');
    }
}
