<?php

namespace Database\Factories;

use App\Models\League;
use App\Models\Standing;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Standing>
 */
class StandingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $played = fake()->numberBetween(4, 14);
        $won = fake()->numberBetween(0, $played);

        return [
            'season_id' => fake()->numberBetween(1, 9_000_000),
            'league_id' => League::factory(),
            'team_id' => Team::factory(),
            'position' => fake()->numberBetween(1, 8),
            'played' => $played,
            'won' => $won,
            'lost' => $played - $won,
            'points' => $won * 2,
            'net_run_rate' => fake()->randomFloat(3, -1.5, 1.5),
            'recent_form' => ['W', 'L', 'W'],
        ];
    }
}
