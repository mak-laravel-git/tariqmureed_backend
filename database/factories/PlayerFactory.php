<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->name('male');

        return [
            'id' => fake()->unique()->numberBetween(1, 9_000_000),
            'team_id' => Team::factory(),
            'fullname' => $name,
            'slug' => Str::slug($name),
            'image' => null,
            'date_of_birth' => fake()->dateTimeBetween('-38 years', '-18 years')->format('Y-m-d'),
            'gender' => 'm',
            'batting_style' => 'right-hand-bat',
            'bowling_style' => 'legbreak',
            'position' => 'Batsman',
            'country_id' => null,
        ];
    }
}
