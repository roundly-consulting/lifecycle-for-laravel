<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A subject's tenant context cannot be restored — its team was deleted, or the team it stores
 * does not exist. The sweep skips such a schedule (counted as `context missing`) instead of
 * erroring on it every run.
 */
final class SubjectContextUnavailableException extends LifecycleException
{
    public static function teamMissing(Model $subject, mixed $team): self
    {
        return new self(sprintf(
            'The [%s #%s] belongs to team [%s], which does not exist (deleted or never stored): its tenant context cannot be restored.',
            $subject::class,
            (string) $subject->getKey(),
            is_int($team) || is_string($team) ? (string) $team : 'none',
        ));
    }
}
