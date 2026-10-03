<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use Throwable;

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

    public static function modelMustExtend(string $key, string $base, ?Throwable $previous = null): self
    {
        return new self(sprintf('The [%s] model must be an existing class that extends [%s].', $key, $base), previous: $previous);
    }

    public static function notAList(string $key): self
    {
        return new self(sprintf('The [%s] setting must be a list.', $key));
    }

    public static function notASubject(string $key, mixed $value): self
    {
        return new self(sprintf(
            'Every entry of [%s] must be an Eloquent model class implementing LifecycleSubject, [%s] given.',
            $key,
            is_string($value) ? $value : get_debug_type($value),
        ));
    }

    public static function statementBinlog(Throwable $previous): self
    {
        return new self(
            'MySQL refused a write at READ COMMITTED because binary logging uses binlog_format=STATEMENT. '
            .'Use ROW or MIXED binary logging, or set lifecycle.transactions.mysql_read_committed to false '
            .'(quota counts then use locking reads).',
            previous: $previous,
        );
    }
}
