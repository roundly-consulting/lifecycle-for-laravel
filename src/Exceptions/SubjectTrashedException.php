<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A soft-deleted subject cannot change state until it is restored.
 */
final class SubjectTrashedException extends LifecycleException
{
    public static function for(Model $subject): self
    {
        return new self(sprintf(
            'The [%s #%s] is soft-deleted; restore it before changing its lifecycle.',
            $subject::class,
            (string) $subject->getKey(),
        ));
    }
}
