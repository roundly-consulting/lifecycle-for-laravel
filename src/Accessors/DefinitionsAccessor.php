<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Accessors;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Lifecycle\Definition\ValidationReport;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Graph\GraphExporter;

/**
 * `Lifecycles::definitions()`: compiled definitions, validation reports and graphs.
 */
final readonly class DefinitionsAccessor
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * @param  class-string<LifecycleDefinition>  $definition
     */
    public function get(string $definition): CompiledDefinition
    {
        return $this->registry()->get($definition);
    }

    /**
     * The definition behind a model's lifecycle attribute (the primary one when null).
     *
     * @param  Model|class-string<Model>  $model
     */
    public function of(Model|string $model, ?string $lifecycle = null): CompiledDefinition
    {
        return $this->registry()->of($model, $lifecycle);
    }

    /**
     * Every error and warning; never throws for an invalid definition.
     *
     * @param  class-string<LifecycleDefinition>  $definition
     */
    public function validate(string $definition): ValidationReport
    {
        return $this->registry()->validate($definition);
    }

    /**
     * @param  class-string<LifecycleDefinition>  $definition
     */
    public function graph(string $definition, ?GraphFormat $format = null): string
    {
        return $this->render($this->get($definition), $format);
    }

    public function render(CompiledDefinition $definition, ?GraphFormat $format = null): string
    {
        return $this->container->make(GraphExporter::class)->export($definition, $format);
    }

    /**
     * The definitions listed in `lifecycle.definitions`.
     *
     * @return list<class-string<LifecycleDefinition>>
     */
    public function registered(): array
    {
        $definitions = config('lifecycle.definitions', []);

        if (! is_array($definitions)) {
            throw InvalidLifecycleConfigurationException::notAList('lifecycle.definitions');
        }

        $registered = [];

        foreach ($definitions as $definition) {
            if (! is_string($definition) || ! is_subclass_of($definition, LifecycleDefinition::class)) {
                throw InvalidLifecycleConfigurationException::notADefinition('lifecycle.definitions', $definition);
            }

            $registered[] = $definition;
        }

        return $registered;
    }

    /**
     * Drop every compiled definition (tests, or after changing a definition at runtime).
     */
    public function flush(): void
    {
        $this->registry()->flush();
    }

    private function registry(): DefinitionRegistry
    {
        return $this->container->make(DefinitionRegistry::class);
    }
}
