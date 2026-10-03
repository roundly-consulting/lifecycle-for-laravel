<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

/**
 * An expiry change that the current state cannot take.
 */
final class ExpiryException extends LifecycleException
{
    public static function stateCannotExpire(string $state): self
    {
        return new self(sprintf('The state [%s] declares no expiry.', $state));
    }

    public static function noPendingExpiry(string $state): self
    {
        return new self(sprintf('The current stay in [%s] has no pending expiry.', $state));
    }

    public static function noTtl(string $state): self
    {
        return new self(sprintf('The state [%s] has no fixed TTL; pass the interval to renew() explicitly.', $state));
    }
}
