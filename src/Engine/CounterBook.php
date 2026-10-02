<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Per-transition occurrence counters kept on the state record (`{"reopen": {"count": 2,
 * "last_at": "2026-10-02 08:00:00"}}`). Limits and cooldowns read these, never COUNT(*) over
 * history, so pruning history never resets a limit; a rollback puts the entry back.
 *
 * @internal
 */
final class CounterBook
{
    /**
     * @param  array<string, array<string, mixed>>|null  $counters
     * @return array<string, mixed>|null
     */
    public static function entry(?array $counters, string $transition): ?array
    {
        return $counters[$transition] ?? null;
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $counters
     */
    public static function count(?array $counters, string $transition): int
    {
        $count = self::entry($counters, $transition)['count'] ?? 0;

        return is_int($count) ? $count : (int) (is_numeric($count) ? $count : 0);
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $counters
     */
    public static function lastAt(?array $counters, string $transition): ?CarbonImmutable
    {
        $last = self::entry($counters, $transition)['last_at'] ?? null;

        return is_string($last) ? CarbonImmutable::createFromFormat(Clock::FORMAT, $last, 'UTC') ?: null : null;
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $counters
     * @return array<string, array<string, mixed>>
     */
    public static function increment(?array $counters, string $transition, CarbonImmutable $now): array
    {
        $counters ??= [];
        $counters[$transition] = ['count' => self::count($counters, $transition) + 1, 'last_at' => Clock::format($now)];

        return $counters;
    }

    /**
     * Put one entry back as it was before a row (null = the transition had never run).
     *
     * @param  array<string, array<string, mixed>>|null  $counters
     * @param  array<string, mixed>|null  $entry
     * @return array<string, array<string, mixed>>|null
     */
    public static function restore(?array $counters, string $transition, ?array $entry): ?array
    {
        $counters ??= [];

        if ($entry === null) {
            unset($counters[$transition]);
        } else {
            $counters[$transition] = $entry;
        }

        return $counters === [] ? null : $counters;
    }
}
