<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

/**
 * More than one transition leads from the current state to the requested one; apply one of
 * them by name instead.
 */
final class AmbiguousTransitionException extends LifecycleException
{
    /** @var list<string> */
    private array $names = [];

    /**
     * @param  list<string>  $names
     */
    public static function between(string $from, string $to, array $names): self
    {
        $exception = new self(sprintf(
            'Several transitions lead from [%s] to [%s]: %s. Apply one of them by name.',
            $from,
            $to,
            implode(', ', $names),
        ));
        $exception->names = $names;

        return $exception;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }
}
