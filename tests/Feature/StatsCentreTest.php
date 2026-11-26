<?php

use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamRanking;

/**
 * @return array<string, mixed>
 */
function careerRow(string $type, int $runs, int $innings, int $wickets = 0, int $conceded = 0, int $highest = 0, int $balls = 0): array
{
    return [
        'type' => $type,
        'batting' => ['matches' => $innings, 'innings' => $innings, 'not_outs' => 0, 'runs_scored' => $runs, 'balls_faced' => $balls, 'highest_inning_score' => $highest, 'hundreds' => intdiv($highest, 100), 'six_x' => 3],
        'bowling' => ['matches' => $innings, 'overs' => 60, 'runs' => $conceded, 'wickets' => $wickets, 'five_wickets' => $wickets >= 25 ? 1 : 0],
    ];
}

it('ranks players by their career figures in a format', function () {
    $india = Team::factory()->national()->create(['name' => 'India']);
    Player::factory()->create(['team_id' => $india->id, 'fullname' => 'Batter One', 'career' => [careerRow('ODI', 900, 20, highest: 140, balls: 800), careerRow('Test', 5000, 80)]]);
    Player::factory()->create(['team_id' => $india->id, 'fullname' => 'Batter Two', 'career' => [careerRow('ODI', 1200, 30, highest: 98, balls: 1300)]]);
    Player::factory()->create(['team_id' => $india->id, 'fullname' => 'Bowler', 'career' => [careerRow('ODI', 40, 12, wickets: 30, conceded: 600)]]);
    Player::factory()->create(['team_id' => $india->id, 'fullname' => 'No Career', 'career' => null]);

    $data = $this->getJson('/api/v1/stats?format=ODI')->assertOk()->assertJsonPath('data.scope', 'player')->json('data');
    $tables = collect($data['tables'])->keyBy('id');

    expect(collect($tables['most-runs']['rows'])->pluck('name')->all())->toBe(['Batter Two', 'Batter One', 'Bowler'])
        ->and($tables['most-runs']['rows'][0])->toMatchArray(['rank' => 1, 'value' => '1,200', 'note' => 'India · 30 matches'])
        ->and($tables['most-runs']['eyebrow'])->toBe('PLAYER · ODI')
        ->and($tables['most-wickets']['rows'][0])->toMatchArray(['name' => 'Bowler', 'value' => '30'])
        ->and($tables['bowling-average']['rows'][0]['value'])->toBe('20.00')
        ->and($tables['strike-rate']['rows'][0])->toMatchArray(['name' => 'Batter One', 'value' => '112.50'])
        ->and($data['metricCount'])->toBe(count($data['tables']));

    $all = collect($this->getJson('/api/v1/stats')->json('data.tables'))->keyBy('id');

    expect($all['most-runs']['rows'][0])->toMatchArray(['name' => 'Batter One', 'value' => '5,900']);
});

it('builds team and tournament leaderboards from rankings, stored matches and league tables', function () {
    $league = League::factory()->create(['name' => 'Big Bash League', 'format' => 'T20']);
    $home = Team::factory()->create(['name' => 'Sydney Sixers']);
    $away = Team::factory()->create(['name' => 'Perth Scorchers']);
    TeamRanking::factory()->create(['team_id' => $home->id, 'format' => 'ODI', 'rating' => 121, 'position' => 1]);
    Fixture::factory()->finished()->create([
        'league_id' => $league->id, 'type' => 'T20', 'local_team_id' => $home->id, 'visitor_team_id' => $away->id, 'winner_team_id' => $home->id,
        'runs' => [['team_id' => $home->id, 'inning' => 1, 'score' => 220, 'wickets' => 4], ['team_id' => $away->id, 'inning' => 2, 'score' => 180, 'wickets' => 9]],
        'batting' => [['team_id' => $home->id, 'six_x' => 12], ['team_id' => $away->id, 'six_x' => 5]],
    ]);
    Standing::factory()->create(['league_id' => $league->id, 'team_id' => $home->id, 'played' => 8, 'won' => 6, 'points' => 12, 'net_run_rate' => 1.25]);

    $team = collect($this->getJson('/api/v1/stats?scope=team&format=T20')->assertOk()->json('data.tables'))->keyBy('id');

    expect($team['team-totals']['rows'][0])->toMatchArray(['name' => 'Sydney Sixers', 'value' => '220/4'])
        ->and($team['team-wins']['rows'][0])->toMatchArray(['name' => 'Sydney Sixers', 'value' => '1'])
        ->and($team['team-sixes']['rows'][0]['value'])->toBe('12')
        ->and($team)->not->toHaveKey('team-rating');

    $odi = collect($this->getJson('/api/v1/stats?scope=team&format=ODI')->json('data.tables'))->keyBy('id');
    expect($odi['team-rating']['rows'][0])->toMatchArray(['name' => 'Sydney Sixers', 'value' => '121']);

    $tournament = collect($this->getJson('/api/v1/stats?scope=tournament')->json('data.tables'))->keyBy('id');
    expect($tournament['table-points']['rows'][0])->toMatchArray(['name' => 'Sydney Sixers', 'value' => '12', 'note' => 'Big Bash League · played 8'])
        ->and($tournament['table-nrr']['rows'][0]['value'])->toBe('+1.250');

    $this->getJson('/api/v1/stats?format=T100')->assertUnprocessable();
});

it('lists the record holder of each kind per format and counts records per group', function () {
    $team = Team::factory()->national()->create(['name' => 'Australia']);
    Player::factory()->create(['team_id' => $team->id, 'fullname' => 'Run Machine', 'career' => [careerRow('Test', 9000, 150, highest: 334), careerRow('T20I', 2000, 60, highest: 120)]]);

    $data = $this->getJson('/api/v1/records')->assertOk()->json('data');
    $highest = collect($data['records'])->firstWhere('id', 'batting-highest-individual-score-test');

    expect($highest)->toMatchArray(['holder' => 'Run Machine', 'format' => 'Test', 'value' => '334', 'group' => 'batting'])
        ->and($data['groupCounts']['batting'])->toBeGreaterThan(0)
        ->and($data['groupCounts']['team'])->toBe(0);

    $this->getJson('/api/v1/records?group=bowling&format=Test')->assertOk()->assertJsonPath('data.total', 0)->assertJsonPath('data.records', []);
    $this->getJson('/api/v1/records?format=T20I')->assertOk()->assertJsonPath('data.records.0.holder', 'Run Machine')->assertJsonPath('data.records.0.format', 'T20I');
});
