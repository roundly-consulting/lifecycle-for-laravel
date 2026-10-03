<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Who performs a call: nobody in system context, the explicit `by()` actor, else (when
 * `actor.from_auth` is on) the authenticated user of `actor.guard`. Resolved per call.
 *
 * @internal
 */
final readonly class ActorResolver
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * The model class of the default auth provider's users, when it is one — what an actor
     * most likely is when a transition names no actor types (`lifecycle:validate` sizes keys
     * with it).
     *
     * @return class-string<Model>|null
     */
    public static function defaultActorType(): ?string
    {
        $model = config('auth.providers.users.model');

        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    public function resolve(?Model $explicit, bool $system): ?Model
    {
        if ($system) {
            return null;
        }

        if ($explicit !== null || ! Config::using(InvalidLifecycleConfigurationException::class)->boolean('lifecycle.actor.from_auth', true)) {
            return $explicit;
        }

        $guard = config('lifecycle.actor.guard');
        $user = $this->container->make(AuthFactory::class)
            ->guard(is_string($guard) && $guard !== '' ? $guard : null)
            ->user();

        return $user instanceof Model ? $user : null;
    }
}
