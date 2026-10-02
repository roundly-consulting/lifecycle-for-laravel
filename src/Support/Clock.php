<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;

/**
 * The package's only source of "now": UTC, whole seconds, and honouring
 * `Carbon::setTestNow()`. An arch test keeps every other class off `now()`.
 *
 * @internal
 */
final class Clock
{
    public const string FORMAT = 'Y-m-d H:i:s';

    public static function now(): CarbonImmutable
    {
        // Asked for in UTC: Carbon's test clock re-reads a test instant as wall time in the
        // requested zone, so in a local zone an instant inside a DST fall-back hour would shift.
        return CarbonImmutable::instance(Date::now('UTC'))->utc()->startOfSecond();
    }

    /**
     * Any instant as the UTC string the package stores and binds — never a Carbon binding,
     * whose string form depends on the instance's own timezone.
     */
    public static function format(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format(self::FORMAT);
    }

    public static function utc(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->utc()->startOfSecond();
    }
}
