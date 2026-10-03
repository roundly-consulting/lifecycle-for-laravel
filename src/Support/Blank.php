<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

/**
 * A configured value that is not set: `null`, `''` or whitespace only (`LIFECYCLE_X=` in `.env`).
 * The same rule the toolkit's readers apply, for the package's own readers.
 *
 * @internal
 */
final class Blank
{
    public static function is(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
