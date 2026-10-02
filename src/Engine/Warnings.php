<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RoundlyConsulting\Lifecycle\Support\Durations;

/**
 * Expiry warnings: leads largest first, each fired at most once per schedule row. Leads
 * whose instant has already passed collapse into one warning carrying the most imminent of
 * them; nothing fires once the expiry itself has passed.
 *
 * @internal
 */
final class Warnings
{
    /**
     * When the first warning is due: `expires_at − largest lead`, even when that is already
     * past (the next pass then collapses every passed lead into one warning).
     *
     * @param  list<CarbonInterval>  $leads  largest first
     */
    public static function first(?CarbonImmutable $expiresAt, array $leads): ?CarbonImmutable
    {
        return $expiresAt === null || $leads === [] ? null : Durations::sub($expiresAt, $leads[0]);
    }

    /**
     * @param  list<CarbonInterval>  $leads  largest first
     */
    public static function advance(array $leads, CarbonImmutable $expiresAt, CarbonImmutable $now, int $sent): WarningStep
    {
        if ($expiresAt->lessThanOrEqualTo($now)) {
            return new WarningStep($sent, null, null);
        }

        $due = null;
        $index = $sent;

        while (isset($leads[$index]) && Durations::sub($expiresAt, $leads[$index])->lessThanOrEqualTo($now)) {
            $due = $leads[$index];
            $index++;
        }

        $next = isset($leads[$index]) ? Durations::sub($expiresAt, $leads[$index]) : null;

        return new WarningStep($index, $next, $due);
    }
}
