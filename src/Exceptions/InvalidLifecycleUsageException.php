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

    public static function quotaScopeChanged(string $class, string $attribute): self
    {
        return new self(sprintf(
            'The quota scope column [%s] of [%s] changed during a transition into a state with that quota. The quota counts the stored partition, so save the column on its own, before or after the transition.',
            $attribute,
            $class,
        ));
    }

    public static function rateLimitKeyTooLong(string $transition, int $length, int $limit): self
    {
        return new self(sprintf(
            'The rate-limit key of [%s] would be %d characters in the cache (at most %d): give the subject and actor models short morph map aliases (Relation::enforceMorphMap()) or shorten lifecycle.rate_limits.prefix.',
            $transition,
            $length,
            $limit,
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

    public static function invalidTtl(mixed $value): self
    {
        return new self(sprintf(
            'A TTL closure must return an interval, an instant or null, %s returned.',
            get_debug_type($value),
        ));
    }

    public static function tooManyRollbackSteps(int $max): self
    {
        return new self(sprintf('A rollback may revert at most %d rows (rollback.max_steps).', $max));
    }

    /**
     * @param  list<int|string>  $keys
     */
    public static function undeclaredPayload(string $transition, array $keys): self
    {
        return new self(sprintf(
            'Transition [%s] declares no rules(), so its payload [%s] would be dropped. Declare rules() for the keys it accepts.',
            $transition,
            implode(', ', array_map(strval(...), $keys)),
        ));
    }
}
