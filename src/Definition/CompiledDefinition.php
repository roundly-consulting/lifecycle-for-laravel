<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use BackedEnum;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;

/**
 * The immutable, validated form of a LifecycleDefinition — compiled once per process and
 * shared by every subject that uses it.
 */
final readonly class CompiledDefinition
{
    /** @var array<string, list<string>> state key => names of the transitions leaving it */
    private array $outgoing;

    /**
     * @param  class-string<LifecycleDefinition>|string  $class
     * @param  list<StateDefinition>  $states  in declaration order
     * @param  list<TransitionDefinition>  $transitions  in declaration order
     * @param  list<Guard|class-string<Guard>>  $guards  lifecycle-wide guards
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $class,
        public StateCodec $codec,
        public array $states,
        public string $initial,
        public array $transitions,
        public array $guards = [],
        public ?string $label = null,
        public array $meta = [],
    ) {
        $outgoing = [];

        foreach ($states as $state) {
            $outgoing['s'.$state->key] = [];
        }

        foreach ($transitions as $transition) {
            foreach ($transition->from as $from) {
                $outgoing['s'.$from][] = $transition->name;
            }
        }

        $this->outgoing = $outgoing;
    }

    public function hasState(mixed $state): bool
    {
        return $this->codec->tryKey($state) !== null;
    }

    public function key(mixed $state): string
    {
        return $this->codec->key($state);
    }

    public function decode(mixed $raw): BackedEnum|string
    {
        return $this->codec->decode($raw);
    }

    public function encode(mixed $state): string|int
    {
        return $this->codec->encode($state);
    }

    public function value(string $key): BackedEnum|string
    {
        return $this->codec->value($key);
    }

    public function state(string $key): StateDefinition
    {
        foreach ($this->states as $state) {
            if ($state->key === $key) {
                return $state;
            }
        }

        throw UnknownStateException::undeclared($key, $this->class);
    }

    public function initialState(): StateDefinition
    {
        return $this->state($this->initial);
    }

    public function isTerminal(string $key): bool
    {
        return $this->state($key)->terminal;
    }

    /**
     * @return list<string>
     */
    public function terminalKeys(): array
    {
        $keys = [];

        foreach ($this->states as $state) {
            if ($state->terminal) {
                $keys[] = $state->key;
            }
        }

        return $keys;
    }

    /**
     * @return list<BackedEnum|string>
     */
    public function values(): array
    {
        return array_map(static fn (StateDefinition $state): BackedEnum|string => $state->value, $this->states);
    }

    public function hasTransition(string $name): bool
    {
        return $this->transition($name) !== null;
    }

    public function transition(string $name): ?TransitionDefinition
    {
        foreach ($this->transitions as $transition) {
            if ($transition->name === $name) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * @return list<TransitionDefinition>
     */
    public function transitionsFrom(string $state): array
    {
        $transitions = [];

        foreach ($this->outgoing['s'.$state] ?? [] as $name) {
            $transition = $this->transition($name);

            if ($transition !== null) {
                $transitions[] = $transition;
            }
        }

        return $transitions;
    }

    /**
     * @return list<TransitionDefinition>
     */
    public function transitionsBetween(string $from, string $to): array
    {
        return array_values(array_filter(
            $this->transitionsFrom($from),
            static fn (TransitionDefinition $transition): bool => $transition->to === $to,
        ));
    }

    public function stateLabel(string $key): string
    {
        return $this->state($key)->label();
    }

    /**
     * Every state that declares an expiry, keyed by nothing (declaration order).
     *
     * @return list<StateDefinition>
     */
    public function expiringStates(): array
    {
        return array_values(array_filter($this->states, static fn (StateDefinition $state): bool => $state->ttl !== null));
    }
}
