<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;

/**
 * @extends Factory<LifecycleSchedule>
 */
final class LifecycleScheduleFactory extends Factory
{
    protected $model = LifecycleSchedule::class;

    /**
     * Build the model the host configured in `lifecycle.models.schedule`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<LifecycleSchedule>
     */
    public function modelName(): string
    {
        return ScheduleModel::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => 'subject',
            'subject_id' => $this->faker->numberBetween(1, 1_000_000),
            'lifecycle' => 'status',
            'kind' => ScheduleKind::Transition,
            'transition' => 'publish',
            'for_state' => 'draft',
            'status' => ScheduleStatus::Pending,
            'pending_slot' => 'publish',
            'due_at' => Clock::now()->addDay(),
        ];
    }

    public function expiry(string $transition = 'expire', string $forState = 'active'): self
    {
        return $this->state(fn (): array => [
            'kind' => ScheduleKind::Expiry,
            'transition' => $transition,
            'for_state' => $forState,
            'pending_slot' => '@expiry',
            'expires_at' => Clock::now()->addDay(),
        ]);
    }

    public function due(): self
    {
        return $this->state(fn (): array => ['due_at' => Clock::now()->subMinute()]);
    }

    public function paused(): self
    {
        return $this->state(fn (): array => ['status' => ScheduleStatus::Paused]);
    }
}
