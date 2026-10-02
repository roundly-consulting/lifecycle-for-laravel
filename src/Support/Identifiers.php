<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

/**
 * Validates every name that ends up in SQL or a storage column, so a definition cannot
 * smuggle an expression in through a column or transition name.
 *
 * @internal
 */
final class Identifiers
{
    public static function isTransitionName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/', $name) === 1;
    }

    public static function isColumn(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) === 1;
    }

    public static function isStateKey(string $key): bool
    {
        return $key !== '' && mb_strlen($key) <= 64;
    }
}
