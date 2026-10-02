<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * Strict writes are on and a model save changed a lifecycle attribute directly.
 */
final class DirectStateWriteException extends LifecycleException
{
    public static function for(Model $subject, string $attribute): self
    {
        return new self(sprintf(
            'The lifecycle attribute [%s] of [%s] changes only through transitions. Use a transition, or wrap a deliberate write in Lifecycles::allowDirectWrites().',
            $attribute,
            $subject::class,
        ));
    }
}
