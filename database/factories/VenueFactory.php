<?php

namespace Database\Factories;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 9_000_000),
            'name' => fake()->unique()->city().' Stadium',
            'city' => fake()->city(),
            'country_id' => null,
            'capacity' => fake()->numberBetween(10_000, 90_000),
            'floodlight' => true,
        ];
    }
}
