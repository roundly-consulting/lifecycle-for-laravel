<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

/**
 * The package was called in a way it cannot honour (a programming error in the host).
 */
final class InvalidLifecycleUsageException extends LifecycleException
{
    public static function invalidDuration(mixed $value): self
    {
        return new self(sprintf(
            'Expected a positive interval such as "30 days", got [%s].',
            is_scalar($value) ? (string) $value : get_debug_type($value),
        ));
    }

    public static function invalidDateTime(mixed $value): self
    {
        return new self(sprintf(
            'Expected a DateTimeInterface or a "Y-m-d H:i:s" UTC string, got [%s].',
            is_scalar($value) ? (string) $value : get_debug_type($value),
        ));
    }

    public static function invalidGuardResult(mixed $result): self
    {
        return new self(sprintf(
            'A lifecycle guard must return bool, null or a Denial, %s returned.',
            get_debug_type($result),
        ));
    }

    public static function invalidExtension(string $kind, mixed $value): self
    {
        return new self(sprintf(
            'The %s [%s] does not implement the expected contract.',
            $kind,
            is_string($value) ? $value : get_debug_type($value),
        ));
    }

    public static function invalidRecord(string $reason): self
    {
        return new self(sprintf('Refusing to save an inconsistent lifecycle row: %s.', $reason));
    }

    public static function invalidRequest(string $reason): self
    {
        return new self(sprintf('Invalid lifecycle request: %s.', $reason));
    }

    public static function dirtyStateAttribute(string $class, string $attribute): self
    {
        return new self(sprintf(
            'The lifecycle attribute [%s] of [%s] has unsaved changes. Transitions change the state; do not set it directly.',
            $attribute,
            $class,
        ));
    }

    public static function invalidDateAttribute(string $attribute, mixed $value): self
    {
        return new self(sprintf(
            'The attribute [%s] must hold a date, %s given.',
            $attribute,
            get_debug_type($value),
        ));
    }

    public static function invalidQuota(string $quota, int $max): self
    {
        return new self(sprintf('The quota [%s] resolved to a negative maximum (%d).', $quota, $max));
    }
}
