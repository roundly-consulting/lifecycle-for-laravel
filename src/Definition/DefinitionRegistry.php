<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleDefinitionException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownLifecycleException;

/**
 * Compiles each definition class once per process and keeps only the immutable result.
 * Definitions are built with `new $class()` — no container, so nothing request-scoped can be
 * captured in the memo (Octane).
 *
 * @internal
 */
final class DefinitionRegistry
{
    /** @var array<string, CompiledDefinition> */
    private array $compiled = [];

    public function get(string $definition): CompiledDefinition
    {
        if (array_key_exists($definition, $this->compiled)) {
            return $this->compiled[$definition];
        }

        $builder = $this->build($definition)
            ?? throw InvalidLifecycleDefinitionException::fromReport($this->uninstantiable($definition));

        return $this->compiled[$definition] = (new Compiler)->compile($definition, $builder);
    }

    /**
     * Errors and warnings of a definition; never throws for an invalid definition.
     */
    public function validate(string $definition): ValidationReport
    {
        $builder = $this->build($definition);

        return $builder === null
            ? $this->uninstantiable($definition)
            : (new DefinitionValidator)->validate($definition, $builder);
    }

    /**
     * The compiled definition behind a model's lifecycle attribute (the primary one when
     * `$lifecycle` is null).
     */
    public function of(Model|string $model, ?string $lifecycle = null): CompiledDefinition
    {
        $definitions = $this->definitionsOf($model);

        return $this->get($definitions[$this->lifecycleName($model, $lifecycle)]);
    }

    /**
     * The lifecycle attribute a call refers to: the given one when declared, else the first.
     */
    public function lifecycleName(Model|string $model, ?string $lifecycle = null): string
    {
        $definitions = $this->definitionsOf($model);
        $class = is_string($model) ? $model : $model::class;

        if ($lifecycle === null) {
            $first = array_key_first($definitions);

            return $first === null ? throw UnknownLifecycleException::noLifecycles($class) : (string) $first;
        }

        return array_key_exists($lifecycle, $definitions)
            ? $lifecycle
            : throw UnknownLifecycleException::notDeclared($class, $lifecycle);
    }

    /**
     * @return array<string, class-string<LifecycleDefinition>>
     */
    public function definitionsOf(Model|string $model): array
    {
        if (is_string($model)) {
            if (! is_subclass_of($model, Model::class) || ! is_subclass_of($model, LifecycleSubject::class)) {
                throw UnknownLifecycleException::notASubject($model);
            }

            $model = new $model;
        }

        if (! $model instanceof LifecycleSubject) {
            throw UnknownLifecycleException::notASubject($model::class);
        }

        return $model->lifecycleDefinitions();
    }

    public function flush(): void
    {
        $this->compiled = [];
    }

    private function build(string $definition): ?LifecycleBuilder
    {
        if (! is_subclass_of($definition, LifecycleDefinition::class)) {
            throw UnknownLifecycleException::unknownDefinition($definition);
        }

        $reflection = new ReflectionClass($definition);
        $constructor = $reflection->getConstructor();

        if ($reflection->isAbstract() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            return null;
        }

        $builder = new LifecycleBuilder;
        (new $definition)->define($builder);

        return $builder;
    }

    private function uninstantiable(string $definition): ValidationReport
    {
        return new ValidationReport($definition, [
            new Issue(IssueCode::UninstantiableDefinition, 'class', ['definition' => $definition]),
        ]);
    }
}
