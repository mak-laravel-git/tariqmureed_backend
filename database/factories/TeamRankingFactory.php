<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamRanking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamRanking>
 */
class TeamRankingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $matches = fake()->numberBetween(20, 60);
        $rating = fake()->numberBetween(60, 130);

        return [
            'team_id' => Team::factory()->national(),
            'format' => 'ODI',
            'gender' => 'men',
            'position' => fake()->numberBetween(1, 20),
            'matches' => $matches,
            'points' => $matches * $rating,
            'rating' => $rating,
        ];
    }
}
