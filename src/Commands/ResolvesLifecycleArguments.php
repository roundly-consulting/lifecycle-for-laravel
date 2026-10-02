<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Support\SubjectResolver;

/**
 * Turns a command argument into a definition: a definition class, or `Model:attribute` where
 * the model is a morph alias or a class name.
 *
 * @internal
 */
final readonly class ResolvesLifecycleArguments
{
    public function __construct(
        private DefinitionRegistry $registry,
    ) {}

    public function definition(string $argument): CompiledDefinition
    {
        if (str_contains($argument, ':')) {
            [$model, $attribute] = explode(':', $argument, 2);

            return $this->registry->of($this->model($model), $attribute === '' ? null : $attribute);
        }

        return $this->registry->get($argument);
    }

    /**
     * A console argument as a string (an absent or non-string one is empty).
     */
    public static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /**
     * @return class-string<Model&LifecycleSubject>
     */
    public function model(string $argument): string
    {
        return SubjectResolver::classFor($argument);
    }
}
