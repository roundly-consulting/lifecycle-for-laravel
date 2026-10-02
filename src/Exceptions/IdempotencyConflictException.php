<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

/**
 * An idempotency key was reused for a different transition.
 */
final class IdempotencyConflictException extends LifecycleException
{
    public static function for(string $key, ?string $recorded, ?string $requested): self
    {
        return new self(sprintf(
            'The idempotency key [%s] was already used for [%s], not [%s].',
            $key,
            (string) $recorded,
            (string) $requested,
        ));
    }
}
