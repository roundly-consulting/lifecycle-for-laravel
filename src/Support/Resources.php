<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Http\Resources\TransitionActorResource;

/**
 * The one place that resolves the configured API resources (`lifecycle.resources.*`).
 *
 * @internal
 */
final class Resources
{
    /**
     * The resource a history row's `actor` renders through. Blank or `null` is the packaged
     * {@see TransitionActorResource}; anything but an instantiable `JsonResource` subclass (the
     * bare base and abstract subclasses included) throws — it never falls back to the packaged
     * resource.
     *
     * @return class-string<JsonResource>
     */
    public static function actor(): string
    {
        $configured = config('lifecycle.resources.actor');

        if (Blank::is($configured)) {
            return TransitionActorResource::class;
        }

        if (! is_string($configured) || ! is_subclass_of($configured, JsonResource::class) || ! (new ReflectionClass($configured))->isInstantiable()) {
            throw InvalidLifecycleConfigurationException::notAResource('lifecycle.resources.actor', $configured);
        }

        return $configured;
    }
}
