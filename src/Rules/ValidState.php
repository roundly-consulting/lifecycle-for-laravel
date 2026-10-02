<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * The input is a state the lifecycle declares — for filters and forms.
 *
 * ```php
 * 'status' => ['required', ValidState::of(Listing::class)],
 * ```
 */
final readonly class ValidState implements ValidationRule
{
    /**
     * @param  Model|class-string  $source  a model (instance or class) or a definition class
     */
    private function __construct(
        private Model|string $source,
        private ?string $lifecycle,
    ) {}

    /**
     * @param  Model|class-string  $modelOrDefinition
     */
    public static function of(Model|string $modelOrDefinition, ?string $lifecycle = null): self
    {
        return new self($modelOrDefinition, $lifecycle);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->definition()->hasState($value)) {
            $fail('lifecycle::validation.valid_state')->translate();
        }
    }

    private function definition(): CompiledDefinition
    {
        $registry = App::make(DefinitionRegistry::class);

        return is_string($this->source) && is_subclass_of($this->source, LifecycleDefinition::class)
            ? $registry->get($this->source)
            : $registry->of($this->source, $this->lifecycle);
    }
}
