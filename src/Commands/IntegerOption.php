<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;

/**
 * Integer command options: the console passes strings, `Artisan::call()` passes real ints —
 * an `is_string()` check would silently ignore the latter.
 *
 * @internal
 */
final class IntegerOption
{
    public static function parse(mixed $value, string $option, int $min = 1): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        $int = filter_var(is_scalar($value) ? (string) $value : '', FILTER_VALIDATE_INT);

        if ($int === false || $int < $min) {
            throw InvalidLifecycleUsageException::invalidRequest(sprintf('--%s must be an integer of at least %d', $option, $min));
        }

        return $int;
    }
}
