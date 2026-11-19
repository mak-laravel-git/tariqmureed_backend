<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city().' Cricketers';

        return [
            'id' => fake()->unique()->numberBetween(1, 9_000_000),
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => Str::upper(fake()->lexify('???')),
            'image' => null,
            'country_id' => null,
            'national_team' => false,
        ];
    }

    public function national(): static
    {
        return $this->state(['national_team' => true]);
    }
}
