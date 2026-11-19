<?php

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fixture>
 */
class FixtureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = fake()->unique()->numberBetween(1, 9_000_000);

        return [
            'id' => $id,
            'slug' => 'match-'.$id,
            'league_id' => League::factory(),
            'season_id' => null,
            'round' => '1st T20I',
            'local_team_id' => Team::factory(),
            'visitor_team_id' => Team::factory(),
            'venue_id' => null,
            'starting_at' => fake()->dateTimeBetween('+1 day', '+10 days'),
            'type' => 'T20I',
            'status' => 'NS',
            'is_live' => false,
        ];
    }

    public function live(string $status = '1st Innings'): static
    {
        return $this->state([
            'status' => $status,
            'is_live' => true,
            'starting_at' => now()->subHour(),
        ]);
    }

    public function finished(): static
    {
        return $this->state([
            'status' => 'Finished',
            'is_live' => false,
            'starting_at' => now()->subDay(),
            'note' => 'Won by 6 wickets',
        ]);
    }
}
