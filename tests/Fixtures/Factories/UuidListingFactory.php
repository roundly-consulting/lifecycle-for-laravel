<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\UuidListing;

/**
 * @extends Factory<UuidListing>
 */
final class UuidListingFactory extends Factory
{
    protected $model = UuidListing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}
