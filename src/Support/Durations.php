<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use DateInterval;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use Throwable;

/**
 * Interval parsing and calendar arithmetic. Years and months are added without overflow
 * (Jan 31 + 1 month = Feb 28/29); everything smaller is an exact UTC duration, so 30 days
 * are always 720 hours, across any DST change.
 *
 * Every interval this class returns is normalised: all components non-negative, and the
 * sign carried by `invert`.
 *
 * @internal
 */
final class Durations
{
    /**
     * A strictly positive interval, or null when the input is not one.
     */
    public static function tryParse(mixed $value): ?CarbonInterval
    {
        $interval = self::tryOffset($value);

        return $interval === null || $interval->invert === 1 || self::isZero($interval) ? null : $interval;
    }

    public static function parse(mixed $value): CarbonInterval
    {
        return self::tryParse($value) ?? throw InvalidLifecycleUsageException::invalidDuration($value);
    }

    /**
     * A signed interval (`'-1 day'` is one day before): zero is allowed, mixed signs are not.
     */
    public static function tryOffset(mixed $value): ?CarbonInterval
    {
        if (! $value instanceof DateInterval && ! is_string($value)) {
            return null;
        }

        try {
            $interval = CarbonInterval::make($value);
        } catch (Throwable) {
            return null;
        }

        return $interval instanceof CarbonInterval ? self::normalise($interval) : null;
    }

    public static function fromConfig(string $key): CarbonInterval
    {
        $value = config($key);

        return self::tryParse($value) ?? throw InvalidLifecycleConfigurationException::invalidDuration($key, $value);
    }

    /**
     * A configured interval where `null` means "none" (e.g. an unlimited rollback window).
     */
    public static function nullableFromConfig(string $key): ?CarbonInterval
    {
        return config($key) === null ? null : self::fromConfig($key);
    }

    public static function add(CarbonImmutable $instant, CarbonInterval $interval): CarbonImmutable
    {
        $sign = $interval->invert === 1 ? -1 : 1;

        return $instant
            ->addYearsNoOverflow($sign * $interval->y)
            ->addMonthsNoOverflow($sign * $interval->m)
            ->addDays($sign * $interval->d)
            ->addHours($sign * $interval->h)
            ->addMinutes($sign * $interval->i)
            ->addSeconds($sign * $interval->s);
    }

    public static function sub(CarbonImmutable $instant, CarbonInterval $interval): CarbonImmutable
    {
        $negated = $interval->copy();
        $negated->invert = $interval->invert === 1 ? 0 : 1;

        return self::add($instant, $negated);
    }

    /**
     * The length of the interval in whole seconds when started at `$from`.
     */
    public static function seconds(CarbonInterval $interval, CarbonImmutable $from): int
    {
        return self::add($from, $interval)->getTimestamp() - $from->getTimestamp();
    }

    /**
     * A stable, human-readable rendering (`30 days`, `1 month 2 hours`) — unlike
     * `forHumans()`, which cascades 30 days into "4 weeks 2 days".
     */
    public static function describe(CarbonInterval $interval): string
    {
        $parts = [];

        foreach (['y' => 'year', 'm' => 'month', 'd' => 'day', 'h' => 'hour', 'i' => 'minute', 's' => 'second'] as $property => $unit) {
            $amount = (int) $interval->{$property};

            if ($amount !== 0) {
                $parts[] = $amount.' '.$unit.($amount === 1 ? '' : 's');
            }
        }

        $text = $parts === [] ? '0 seconds' : implode(' ', $parts);

        return $interval->invert === 1 ? '-'.$text : $text;
    }

    private static function normalise(CarbonInterval $interval): ?CarbonInterval
    {
        $components = [$interval->y, $interval->m, $interval->d, $interval->h, $interval->i, $interval->s];

        $positive = array_filter($components, static fn (int $value): bool => $value > 0) !== [];
        $negative = array_filter($components, static fn (int $value): bool => $value < 0) !== [];

        if ($positive && $negative) {
            return null;
        }

        $invert = $interval->invert === 1;

        if ($negative) {
            $invert = ! $invert;
        }

        $normalised = CarbonInterval::create(
            abs($interval->y),
            abs($interval->m),
            0,
            abs($interval->d),
            abs($interval->h),
            abs($interval->i),
            abs($interval->s),
        );

        $normalised->invert = $invert && ! self::isZero($normalised) ? 1 : 0;

        return $normalised;
    }

    private static function isZero(CarbonInterval $interval): bool
    {
        return $interval->y === 0 && $interval->m === 0 && $interval->d === 0
            && $interval->h === 0 && $interval->i === 0 && $interval->s === 0;
    }
}
