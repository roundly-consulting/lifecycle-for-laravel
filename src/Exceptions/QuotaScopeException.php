<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

/**
 * A quota's scope cannot be used: its encoded values are too long for the mutex key, or a
 * scope column does not exist on the model's table.
 */
final class QuotaScopeException extends LifecycleException
{
    public static function tooLong(string $quota): self
    {
        return new self(sprintf('The scope values of the quota [%s] encode to more than 191 characters.', $quota));
    }

    public static function unknownColumn(string $quota, string $table, string $column): self
    {
        return new self(sprintf('The quota [%s] is scoped by [%s], which the table [%s] does not have.', $quota, $column, $table));
    }
}
