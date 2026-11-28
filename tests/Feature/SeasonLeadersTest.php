<?php

use App\Models\League;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-28 12:00:00', 'UTC'));
});

function leaderSeason(int $id, string $name, string $category = 'International'): Season
{
    return Season::factory()->create(['id' => $id, 'name' => $name, 'league_id' => League::factory()->create(['category' => $category])->id]);
}

/**
 * One season and format of a career, as the feed stores it.
 *
 * @param  array<string, int>  $batting
 * @return array<string, mixed>
 */
function leaderRow(int $season, string $type, array $batting = [], int $wickets = 0): array
{
    return [
        'season_id' => $season,
        'type' => $type,
        'batting' => ['matches' => 5, 'innings' => 5, 'not_outs' => 1, 'runs_scored' => 0, 'balls_faced' => 0, 'highest_inning_score' => 0, 'hundreds' => 0, 'six_x' => 0, ...$batting],
        'bowling' => ['matches' => 0, 'wickets' => $wickets],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $career
 */
function leaderPlayer(string $name, array $career, ?Team $team = null): Player
{
    $team ??= Team::factory()->national()->create(['name' => $name.' Land', 'code' => 'ABC']);

    return Player::factory()->create(['fullname' => $name, 'team_id' => $team->id, 'career' => $career]);
}

it('adds up the season of each format and ranks the run-scorers', function () {
    leaderSeason(1, '2026');
    leaderSeason(2, '2026');
    $india = Team::factory()->national()->create(['name' => 'India', 'code' => 'IND']);
    leaderPlayer('Gill', [
        leaderRow(1, 'ODI', ['matches' => 4, 'innings' => 4, 'not_outs' => 1, 'runs_scored' => 200, 'balls_faced' => 160, 'highest_inning_score' => 120, 'hundreds' => 1]),
        leaderRow(2, 'ODI', ['matches' => 2, 'innings' => 2, 'not_outs' => 0, 'runs_scored' => 100, 'balls_faced' => 80, 'highest_inning_score' => 70]),
    ], $india);
    leaderPlayer('Root', [leaderRow(1, 'ODI', ['runs_scored' => 250])]);
    leaderPlayer('Sharma', [leaderRow(1, 'T20I', ['runs_scored' => 90])]);
    leaderPlayer('Smith', [leaderRow(1, 'Test/5day', ['runs_scored' => 400])]);

    $data = $this->getJson('/api/v1/stats/batting-leaders')->assertOk()->json('data');

    expect(collect($data['formats'])->pluck('seasonLabel')->all())->toBe(['ODI · 2026', 'T20I · 2026', 'Test · 2026'])
        ->and($data['seasonLabel'])->toBe('ODI · 2026')
        ->and(collect($data['rows'])->pluck('playerName')->all())->toBe(['Gill', 'Root'])
        ->and($data['rows'][0])->toBe([
            'playerName' => 'Gill',
            'team' => 'IND',
            'matches' => 6,
            'runs' => 300,
            'highScore' => '120',
            'average' => 60,
            'strikeRate' => 125,
            'hundreds' => 1,
        ])
        ->and($data['rows'][1]['strikeRate'])->toBeNull();
});

it('shows six players at most', function () {
    leaderSeason(1, '2026');

    foreach (range(1, 8) as $number) {
        leaderPlayer("Batter {$number}", [leaderRow(1, 'ODI', ['runs_scored' => $number * 10])]);
    }

    expect($this->getJson('/api/v1/stats/batting-leaders')->json('data.rows.*.playerName'))
        ->toBe(['Batter 8', 'Batter 7', 'Batter 6', 'Batter 5', 'Batter 4', 'Batter 3']);
});

it('counts only international formats of this year', function (int $season, string $type) {
    leaderSeason(1, '2026');
    leaderSeason(2, '2025');
    leaderSeason(3, '2026', 'Domestic');
    leaderPlayer('Counted', [leaderRow(1, 'ODI', ['runs_scored' => 10])]);
    leaderPlayer('Left Out', [leaderRow($season, $type, ['runs_scored' => 900])]);

    expect($this->getJson('/api/v1/stats/batting-leaders')->json('data.formats.*.rows.*.playerName'))->toBe(['Counted']);
})->with([
    'last year' => [2, 'ODI'],
    'a domestic competition' => [3, 'Test'],
    'a domestic format' => [1, 'T20'],
]);

it('gives the season leaders of runs, wickets, sixes and hundreds over all formats', function () {
    leaderSeason(1, '2026');
    $india = Team::factory()->national()->create(['name' => 'India']);
    leaderPlayer('All Formats', [
        leaderRow(1, 'ODI', ['runs_scored' => 600, 'six_x' => 10, 'hundreds' => 2]),
        leaderRow(1, 'T20I', ['runs_scored' => 500, 'six_x' => 30, 'hundreds' => 1]),
    ], $india);
    leaderPlayer('One Format', [leaderRow(1, 'ODI', ['runs_scored' => 900], wickets: 24)], $india);

    expect($this->getJson('/api/v1/stats/highlights')->assertOk()->json('data'))->toBe([
        ['value' => '1,100', 'label' => 'Most runs in 2026', 'context' => 'All Formats · India'],
        ['value' => '24', 'label' => 'Most wickets in 2026', 'context' => 'One Format · India'],
        ['value' => '40', 'label' => 'Most sixes in 2026', 'context' => 'All Formats · India'],
        ['value' => '3', 'label' => 'Most hundreds in 2026', 'context' => 'All Formats · India'],
    ]);
});

it('shows last year while the new season has no figures', function () {
    leaderSeason(1, '2025');
    leaderSeason(2, '2026');
    leaderPlayer('Last Year', [leaderRow(1, 'ODI', ['runs_scored' => 50])]);

    $this->getJson('/api/v1/stats/batting-leaders')->assertJsonPath('data.seasonLabel', 'ODI · 2025');
});

it('writes the code of a women\'s side in full', function () {
    leaderSeason(1, '2026');
    $india = Team::factory()->national()->create(['name' => 'India W', 'code' => 'IND-W']);
    leaderPlayer('Mandhana', [leaderRow(1, 'T20I', ['runs_scored' => 80])], $india);

    $this->getJson('/api/v1/stats/batting-leaders')->assertJsonPath('data.rows.0.team', 'IND W');
});

it('answers with nothing rather than made-up figures when there are none', function () {
    $this->getJson('/api/v1/stats/highlights')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/v1/stats/batting-leaders')->assertOk()->assertJsonPath('data.rows', [])->assertJsonPath('data.formats', []);
});
