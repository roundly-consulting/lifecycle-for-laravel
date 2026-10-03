<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use BackedEnum;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;

/**
 * Translates between raw attribute values, enum cases and state keys. A state key is the
 * string form of the raw backing value — an int-backed `3` is stored as `'3'` in history,
 * schedules and records and decoded back to the case.
 *
 * @internal
 */
final readonly class StateCodec
{
    /**
     * @param  class-string<BackedEnum>|null  $enumClass
     * @param  array<string, BackedEnum|string>  $values  state key => state
     */
    public function __construct(
        public string $definition,
        public ?string $enumClass,
        public array $values,
    ) {}

    public function tryKey(mixed $state): ?string
    {
        if ($state instanceof BackedEnum) {
            if ($this->enumClass === null || ! $state instanceof $this->enumClass) {
                return null;
            }

            $key = (string) $state->value;
        } elseif (is_string($state) || is_int($state)) {
            $key = (string) $state;
        } else {
            return null;
        }

        return array_key_exists($key, $this->values) ? $key : null;
    }

    public function key(mixed $state): string
    {
        return $this->tryKey($state) ?? throw UnknownStateException::undeclared($state, $this->definition);
    }

    public function decode(mixed $raw): BackedEnum|string
    {
        return $this->values[$this->key($raw)];
    }

    public function tryDecode(mixed $raw): BackedEnum|string|null
    {
        $key = $this->tryKey($raw);

        return $key === null ? null : $this->values[$key];
    }

    public function value(string $key): BackedEnum|string
    {
        return $this->values[$key] ?? throw UnknownStateException::undeclared($key, $this->definition);
    }

    /**
     * The raw value written to the host attribute: the enum's backing value, or the string.
     */
    public function encode(mixed $state): string|int
    {
        $value = $this->decode($state);

        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
