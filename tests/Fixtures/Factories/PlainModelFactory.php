<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\PlainModel;

/**
 * @extends Factory<PlainModel>
 */
final class PlainModelFactory extends Factory
{
    protected $model = PlainModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}
