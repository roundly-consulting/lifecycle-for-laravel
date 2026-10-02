<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * The compare-and-swap write found the state already changed: another writer won.
 */
final class ConcurrentTransitionException extends LifecycleException
{
    public static function lostRace(Model $subject, string $lifecycle): self
    {
        return new self(sprintf(
            'The lifecycle [%s] of [%s #%s] was changed concurrently.',
            $lifecycle,
            $subject::class,
            (string) $subject->getKey(),
        ));
    }

    public static function scheduleChanged(int $scheduleId): self
    {
        return new self(sprintf('The lifecycle schedule #%d was changed concurrently.', $scheduleId));
    }
}
