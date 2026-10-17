<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\StateModel;

/**
 * @extends Factory<LifecycleState>
 */
final class LifecycleStateFactory extends Factory
{
    protected $model = LifecycleState::class;

    /**
     * Build the model the host configured in `lifecycle.models.state`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<LifecycleState>
     */
    public function modelName(): string
    {
        return StateModel::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => 'subject',
            'subject_id' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'lifecycle' => 'status',
            'state' => 'draft',
            'entered_at' => Clock::now(),
            'version' => 1,
        ];
    }

    /**
     * The record of one subject's lifecycle (not `for()`, which Factory already defines).
     */
    public function forSubject(Model $subject, string $lifecycle = 'status'): self
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'lifecycle' => $lifecycle,
        ]);
    }

    public function frozen(?string $until = null, ?string $reason = null): self
    {
        return $this->state(fn (): array => [
            'frozen_at' => Clock::now(),
            'frozen_until' => $until,
            'frozen_reason' => $reason,
        ]);
    }
}
