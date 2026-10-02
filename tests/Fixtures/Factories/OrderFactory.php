<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}
