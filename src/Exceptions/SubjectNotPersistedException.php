<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * Lifecycle operations need a saved subject (and one that still exists).
 */
final class SubjectNotPersistedException extends LifecycleException
{
    public static function for(Model $subject): self
    {
        return new self(sprintf(
            'The [%s] must be saved before its lifecycle can change%s.',
            $subject::class,
            $subject->exists ? ' (the row no longer exists)' : '',
        ));
    }
}
