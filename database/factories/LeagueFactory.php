<?php

namespace Database\Factories;

use App\Models\League;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<League>
 */
class LeagueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true).' Cup';

        return [
            'id' => fake()->unique()->numberBetween(1, 9_000_000),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'code' => Str::upper(fake()->lexify('???')),
            'image' => null,
            'type' => 'league',
            'country_id' => null,
            'current_season_id' => null,
            'format' => 'T20',
            'category' => 'Domestic',
            'is_featured' => true,
        ];
    }
}
