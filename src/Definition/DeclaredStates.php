<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use BackedEnum;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Support\Identifiers;

/**
 * The declared state set of a definition, read from `states()`, with the problems found
 * while reading it.
 *
 * @internal
 */
final readonly class DeclaredStates
{
    /**
     * @param  class-string<BackedEnum>|null  $enumClass
     * @param  array<string, BackedEnum|string>  $values
     * @param  list<Issue>  $issues
     */
    private function __construct(
        public ?string $enumClass,
        public array $values,
        public array $issues,
    ) {}

    /**
     * @param  string|list<mixed>|null  $declaration
     */
    public static function from(string|array|null $declaration): self
    {
        if ($declaration === null || $declaration === []) {
            return new self(null, [], [new Issue(IssueCode::NoStates, 'states')]);
        }

        if (is_string($declaration)) {
            if (! enum_exists($declaration) || ! is_subclass_of($declaration, BackedEnum::class)) {
                return new self(null, [], [new Issue(IssueCode::InvalidStateValue, 'states', ['value' => $declaration])]);
            }

            $declaration = $declaration::cases();

            if ($declaration === []) {
                return new self(null, [], [new Issue(IssueCode::NoStates, 'states')]);
            }
        }

        $enumClass = null;
        $plain = false;
        $values = [];
        $issues = [];

        foreach ($declaration as $state) {
            if ($state instanceof BackedEnum) {
                if (($enumClass !== null && ! $state instanceof $enumClass) || $plain) {
                    $issues[] = new Issue(IssueCode::MixedStateTypes, 'states', ['value' => $state::class]);

                    continue;
                }

                $enumClass = $state::class;
                $key = (string) $state->value;
            } elseif (is_string($state) || is_int($state)) {
                if ($enumClass !== null) {
                    $issues[] = new Issue(IssueCode::MixedStateTypes, 'states', ['value' => (string) $state]);

                    continue;
                }

                $plain = true;
                $state = (string) $state;
                $key = $state;
            } else {
                $issues[] = new Issue(IssueCode::InvalidStateValue, 'states', ['value' => get_debug_type($state)]);

                continue;
            }

            if (! Identifiers::isStateKey($key)) {
                $issues[] = new Issue(IssueCode::InvalidStateValue, 'states', ['value' => $key]);

                continue;
            }

            if (array_key_exists($key, $values)) {
                $issues[] = new Issue(IssueCode::DuplicateState, 'states', ['state' => $key]);

                continue;
            }

            $values[$key] = $state;
        }

        return new self($enumClass, $values, $issues);
    }

    public function codec(string $definition): StateCodec
    {
        return new StateCodec($definition, $this->enumClass, $this->values);
    }

    public function resolve(mixed $state): ?string
    {
        return $this->codec('')->tryKey($state);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->values));
    }
}
