<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

/**
 * History rows are never updated or deleted through Eloquent.
 */
final class HistoryIsAppendOnlyException extends LifecycleException
{
    public static function cannotUpdate(int $id): self
    {
        return new self(sprintf('Lifecycle history is append-only: row #%d cannot be updated.', $id));
    }

    public static function cannotDelete(int $id): self
    {
        return new self(sprintf('Lifecycle history is append-only: row #%d cannot be deleted. Use lifecycle:prune.', $id));
    }
}
