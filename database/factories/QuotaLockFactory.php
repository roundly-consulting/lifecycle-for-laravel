<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;

/**
 * @extends Factory<QuotaLock>
 */
final class QuotaLockFactory extends Factory
{
    protected $model = QuotaLock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => 'subject',
            'lifecycle' => 'status',
            'quota' => 'active|user_id',
            'scope_key' => '['.$this->faker->numberBetween(1, 1_000_000).']',
        ];
    }
}
