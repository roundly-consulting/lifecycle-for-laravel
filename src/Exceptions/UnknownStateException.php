<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * A raw value or enum case that the lifecycle definition does not declare.
 */
final class UnknownStateException extends LifecycleException
{
    public static function undeclared(mixed $value, string $definition): self
    {
        return new self(sprintf(
            'The state [%s] is not declared by the lifecycle definition [%s].',
            self::describe($value),
            $definition,
        ));
    }

    public static function notInitialized(Model $subject, string $lifecycle): self
    {
        return new self(sprintf(
            'The lifecycle [%s] of [%s #%s] has no state yet.',
            $lifecycle,
            $subject::class,
            self::describe($subject->getKey()),
        ));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => $value::class.'::'.$value->name,
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
