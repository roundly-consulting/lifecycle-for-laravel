<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Accessors;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Lifecycle\Definition\ValidationReport;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Graph\GraphExporter;

/**
 * `Lifecycles::definitions()`: compiled definitions, validation reports, graphs and the
 * configured subjects.
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
     * The models listed in `lifecycle.subjects`, in config order.
     *
     * @return list<class-string<Model&LifecycleSubject>>
     */
    public function subjects(): array
    {
        $subjects = config('lifecycle.subjects', []);

        if (! is_array($subjects)) {
            throw InvalidLifecycleConfigurationException::notAList('lifecycle.subjects');
        }

        $listed = [];

        foreach ($subjects as $subject) {
            if (! is_string($subject) || ! is_subclass_of($subject, Model::class) || ! is_subclass_of($subject, LifecycleSubject::class)) {
                throw InvalidLifecycleConfigurationException::notASubject('lifecycle.subjects', $subject);
            }

            $listed[] = $subject;
        }

        return $listed;
    }

    /**
     * The definition classes behind every lifecycle of the configured subjects, each once, in
     * config order.
     *
     * @return list<class-string<LifecycleDefinition>>
     */
    public function registered(): array
    {
        $registered = [];

        foreach ($this->subjects() as $subject) {
            foreach ($this->registry()->definitionsOf($subject) as $definition) {
                if (! in_array($definition, $registered, true)) {
                    $registered[] = $definition;
                }
            }
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
