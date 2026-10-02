<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

/**
 * The model, attribute or definition class named does not resolve to a lifecycle.
 */
final class UnknownLifecycleException extends LifecycleException
{
    public static function notASubject(string $class): self
    {
        return new self(sprintf(
            'The model [%s] does not implement RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject.',
            $class,
        ));
    }

    public static function notDeclared(string $class, string $lifecycle): self
    {
        return new self(sprintf(
            'The model [%s] declares no lifecycle on the attribute [%s].',
            $class,
            $lifecycle,
        ));
    }

    public static function noLifecycles(string $class): self
    {
        return new self(sprintf('The model [%s] declares no lifecycle at all.', $class));
    }

    public static function unknownDefinition(string $class): self
    {
        return new self(sprintf(
            'The class [%s] is not a RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition.',
            $class,
        ));
    }
}
