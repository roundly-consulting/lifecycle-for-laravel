<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

/**
 * @extends Factory<Listing>
 */
final class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['title' => $this->faker->sentence(3)];
    }
}
