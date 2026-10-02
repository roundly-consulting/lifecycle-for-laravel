<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use BackedEnum;
use Closure;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\Engine\ClosureGuard;

/**
 * The fluent builder a LifecycleDefinition receives in `define()`.
 *
 * ```php
 * $lifecycle->states(ListingStatus::class)->initial(ListingStatus::Draft)->terminal(ListingStatus::Archived);
 * $lifecycle->state(ListingStatus::Active)->ttl('30 days')->expiresVia('expire');
 * $lifecycle->transition('publish')->from(ListingStatus::Draft)->to(ListingStatus::Active);
 * ```
 */
final class LifecycleBuilder
{
    /** @var class-string<BackedEnum>|string|list<mixed>|null */
    public private(set) string|array|null $stateDeclaration = null;

    public private(set) BackedEnum|string|int|null $initial = null;

    /** @var list<BackedEnum|string|int> */
    public private(set) array $terminal = [];

    /** @var array<string, StateBuilder> */
    public private(set) array $stateBuilders = [];

    /** @var list<TransitionBuilder> */
    public private(set) array $transitionBuilders = [];

    /** @var list<Guard|class-string<Guard>> */
    public private(set) array $guards = [];

    public private(set) ?string $label = null;

    /** @var array<string, mixed> */
    public private(set) array $meta = [];

    /**
     * Every case of a backed enum (`ListingStatus::class`), or a list of enum cases or
     * plain string states.
     *
     * @param  class-string<BackedEnum>|list<BackedEnum|string|int>  $states
     */
    public function states(string|array $states): static
    {
        $this->stateDeclaration = $states;

        return $this;
    }

    /**
     * Settings for one state; calling it again for the same state returns the same builder.
     */
    public function state(BackedEnum|string|int $state): StateBuilder
    {
        $builder = new StateBuilder($state);

        return $this->stateBuilders[$builder->key] ??= $builder;
    }

    public function initial(BackedEnum|string|int $state): static
    {
        $this->initial = $state;

        return $this;
    }

    public function terminal(BackedEnum|string|int ...$states): static
    {
        $this->terminal = [...$this->terminal, ...array_values($states)];

        return $this;
    }

    public function transition(string $name): TransitionBuilder
    {
        $builder = new TransitionBuilder($name);

        $this->transitionBuilders[] = $builder;

        return $builder;
    }

    /**
     * A guard evaluated before every transition's own guards.
     *
     * @param  Guard|class-string<Guard>|Closure  $guard
     */
    public function guard(Guard|string|Closure $guard): static
    {
        $this->guards[] = $guard instanceof Closure ? new ClosureGuard($guard) : $guard;

        return $this;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }
}
