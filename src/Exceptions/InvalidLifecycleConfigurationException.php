<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

/**
 * A `config/lifecycle.php` value is out of range or of the wrong type.
 */
final class InvalidLifecycleConfigurationException extends LifecycleException
{
    public static function invalidDuration(string $key, mixed $value): self
    {
        return new self(sprintf(
            'The [%s] setting must be a positive interval such as "5 minutes", %s given.',
            $key,
            get_debug_type($value),
        ));
    }
}
