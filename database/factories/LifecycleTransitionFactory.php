<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * @extends Factory<LifecycleTransition>
 */
final class LifecycleTransitionFactory extends Factory
{
    protected $model = LifecycleTransition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => 'subject',
            'subject_id' => $this->faker->numberBetween(1, 1_000_000),
            'lifecycle' => 'status',
            'kind' => TransitionKind::Transition,
            'transition' => 'publish',
            'from_state' => 'draft',
            'to_state' => 'active',
            'is_system' => false,
            'version' => 1,
            'occurred_at' => Clock::now(),
        ];
    }

    public function kind(TransitionKind $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    public function reverting(LifecycleTransition $row): self
    {
        return $this->state(fn (): array => [
            'kind' => TransitionKind::Rollback,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'lifecycle' => $row->lifecycle,
            'transition' => $row->transition,
            'from_state' => $row->to_state,
            'to_state' => $row->from_state ?? $row->to_state,
            'reverts_id' => $row->id,
            'version' => $row->version + 1,
        ]);
    }
}
