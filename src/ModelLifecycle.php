<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\StateDefinition;
use RoundlyConsulting\Lifecycle\Definition\TransitionDefinition;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectContextUnavailableException;

/**
 * Class-level operations on one lifecycle of a model: `Lifecycles::model(Order::class)`.
 */
final readonly class ModelLifecycle
{
    /**
     * @param  class-string<Model>  $class
     */
    public function __construct(
        private LifecycleManager $manager,
        public string $class,
        public string $lifecycle,
    ) {}

    public function definition(): CompiledDefinition
    {
        return $this->manager->definitions()->of($this->class, $this->lifecycle);
    }

    /**
     * @return list<BackedEnum|string>
     */
    public function states(): array
    {
        return $this->definition()->values();
    }

    public function initial(): BackedEnum|string
    {
        return $this->definition()->initialState()->value;
    }

    /**
     * @return list<BackedEnum|string>
     */
    public function terminal(): array
    {
        return array_values(array_map(
            static fn (StateDefinition $state): BackedEnum|string => $state->value,
            array_filter($this->definition()->states, static fn (StateDefinition $state): bool => $state->terminal),
        ));
    }

    /**
     * @return list<TransitionDefinition>
     */
    public function transitions(): array
    {
        return $this->definition()->transitions;
    }

    public function graph(?GraphFormat $format = null): string
    {
        return $this->manager->definitions()->render($this->definition(), $format);
    }

    /**
     * Reconcile every row of the model, each inside its own tenant context; returns how many
     * changed. A row whose tenant context is gone is skipped and handed to `$skipped`.
     *
     * @param  (Closure(Model, SubjectContextUnavailableException): void)|null  $skipped
     */
    public function adopt(int $chunk = 500, bool $scheduleExpiry = true, ?Closure $skipped = null): int
    {
        return $this->manager->adoptAll($this->class, $this->lifecycle, $chunk, $scheduleExpiry, $skipped);
    }
}
