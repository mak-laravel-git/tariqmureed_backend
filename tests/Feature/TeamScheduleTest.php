<?php

use App\Models\Fixture;
use App\Models\Team;

function scheduledTeam(string $name = 'India'): Team
{
    return Team::factory()->create(['name' => $name, 'slug' => str($name)->slug()]);
}

it('lists the matches of the team in play or still to come, the nearest first', function () {
    $india = scheduledTeam();
    Fixture::factory()->create(['slug' => 'next-month', 'local_team_id' => $india->id, 'starting_at' => now()->addMonth()]);
    Fixture::factory()->create(['slug' => 'tomorrow', 'visitor_team_id' => $india->id, 'starting_at' => now()->addDay()]);
    Fixture::factory()->live('Stump Day 2')->create(['slug' => 'test-match', 'local_team_id' => $india->id, 'starting_at' => now()->subDays(2)]);
    Fixture::factory()->create(['slug' => 'delayed', 'local_team_id' => $india->id, 'starting_at' => now()->subHours(2)]);
    Fixture::factory()->create(['slug' => 'never-started', 'local_team_id' => $india->id, 'starting_at' => now()->subDays(3)]);
    Fixture::factory()->create(['slug' => 'other-teams', 'starting_at' => now()->addDay()]);

    $data = $this->getJson('/api/v1/teams/india/schedule')->assertOk()->json('data');

    expect($data['team']['name'])->toBe('India')
        ->and(collect($data['upcoming'])->pluck('slug')->all())->toBe(['test-match', 'delayed', 'tomorrow', 'next-month'])
        ->and($data['results'])->toBe([]);
});

it('lists the matches of the team that are over, the newest first', function () {
    $india = scheduledTeam();
    Fixture::factory()->finished()->create(['slug' => 'last-week', 'local_team_id' => $india->id, 'starting_at' => now()->subWeek()]);
    Fixture::factory()->finished()->create(['slug' => 'yesterday', 'visitor_team_id' => $india->id]);
    Fixture::factory()->create(['slug' => 'washed-out', 'local_team_id' => $india->id, 'status' => 'Aban.', 'starting_at' => now()->subDays(3)]);
    Fixture::factory()->finished()->create(['slug' => 'other-teams']);

    $data = $this->getJson('/api/v1/teams/india/schedule')->assertOk()->json('data');

    expect(collect($data['results'])->pluck('slug')->all())->toBe(['yesterday', 'washed-out', 'last-week'])
        ->and($data['upcoming'])->toBe([]);
});

it('keeps the fifty newest results', function () {
    $india = scheduledTeam();

    foreach (range(1, 51) as $daysAgo) {
        Fixture::factory()->finished()->create(['slug' => "day-{$daysAgo}", 'local_team_id' => $india->id, 'starting_at' => now()->subDays($daysAgo)]);
    }

    $results = $this->getJson('/api/v1/teams/india/schedule')->assertOk()->json('data.results');

    expect($results)->toHaveCount(50)
        ->and($results[49]['slug'])->toBe('day-50');
});

it('does not know the schedule of an unknown team', function () {
    $this->getJson('/api/v1/teams/atlantis/schedule')->assertNotFound();
});
