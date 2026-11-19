<?php

use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\Standing;
use App\Models\Team;
use App\Models\Venue;
use Illuminate\Support\Facades\Http;
use Tests\Support\SportMonksPayloads as Payloads;

/**
 * India v Australia in a league called "Test Series", with the given state.
 *
 * @param  array<string, mixed>  $attributes
 */
function cricketFixture(array $attributes = []): Fixture
{
    $league = League::query()->find(3) ?? League::factory()->create(['id' => 3, 'slug' => 'test-series', 'name' => 'Test Series', 'format' => 'T20I', 'category' => 'International', 'current_season_id' => 6]);
    $india = Team::query()->find(40) ?? Team::factory()->national()->create(['id' => 40, 'name' => 'India', 'slug' => 'india', 'code' => 'IND']);
    $australia = Team::query()->find(41) ?? Team::factory()->national()->create(['id' => 41, 'name' => 'Australia', 'slug' => 'australia', 'code' => 'AUS']);
    $venue = Venue::query()->find(96) ?? Venue::factory()->create(['id' => 96, 'name' => 'Wankhede Stadium', 'city' => 'Mumbai']);

    return Fixture::factory()->create([
        'league_id' => $league->id,
        'local_team_id' => $india->id,
        'visitor_team_id' => $australia->id,
        'venue_id' => $venue->id,
        ...$attributes,
    ]);
}

function liveScoreboard(): array
{
    $payload = Payloads::liveFixture();

    return [
        'runs' => $payload['runs'],
        'batting' => $payload['batting'],
        'bowling' => $payload['bowling'],
        'lineup' => $payload['lineup']['data'],
        'toss_won_team_id' => 40,
        'elected' => 'batting',
    ];
}

it('lists matches in the website contract shape', function () {
    cricketFixture(['id' => 1, 'slug' => 'ind-vs-aus-1', 'round' => '1st T20I', 'starting_at' => now()->addDay()]);

    $match = $this->getJson('/api/v1/matches?state=upcoming')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'ind-vs-aus-1')
        ->assertJsonPath('data.0.state', 'upcoming')
        ->assertJsonPath('data.0.competition.slug', 'test-series')
        ->assertJsonPath('data.0.competition.format', 'T20I')
        ->assertJsonPath('data.0.home.team.name', 'India')
        ->assertJsonPath('data.0.home.team.shortName', 'IND')
        ->assertJsonPath('data.0.away.team.slug', 'australia')
        ->assertJsonPath('data.0.venue', 'Wankhede Stadium')
        ->assertJsonPath('data.0.city', 'Mumbai')
        ->assertJsonPath('data.0.stage', '1st T20I')
        ->json('data.0');

    expect($match['home']['team']['colors'])->toHaveKeys(['from', 'to'])
        ->and($match['home']['startTime'])->toMatch('/^\d{2}:\d{2}$/')
        ->and($match['startsAt'])->toContain('T');
});

it('filters matches by state and treats breaks as live', function () {
    cricketFixture(['id' => 1, 'slug' => 'a'])->forceFill(['status' => '1st Innings', 'is_live' => true])->save();
    cricketFixture(['id' => 2, 'slug' => 'b'])->forceFill(['status' => 'Innings Break', 'is_live' => true])->save();
    cricketFixture(['id' => 3, 'slug' => 'c'])->forceFill(['status' => 'Finished', 'starting_at' => now()->subDay()])->save();
    cricketFixture(['id' => 4, 'slug' => 'd']);

    expect($this->getJson('/api/v1/matches?state=live')->json('data.*.slug'))->toEqualCanonicalizing(['a', 'b'])
        ->and($this->getJson('/api/v1/matches?state=break')->json('data.*.slug'))->toBe(['b'])
        ->and($this->getJson('/api/v1/matches?state=result')->json('data.*.slug'))->toBe(['c'])
        ->and($this->getJson('/api/v1/matches?state=upcoming')->json('data.*.slug'))->toBe(['d'])
        ->and($this->getJson('/api/v1/matches')->json('data'))->toHaveCount(4)
        ->and($this->getJson('/api/v1/matches?limit=2')->json('data'))->toHaveCount(2);
});

it('shows the score, the batting side and the toss for a live match', function () {
    cricketFixture(['id' => 20094, 'slug' => 'ind-vs-aus-20094', ...liveScoreboard(), 'status' => '1st Innings', 'is_live' => true, 'starting_at' => now()->subHour(), 'note' => 'Strong start']);

    $match = $this->getJson('/api/v1/matches?state=live')->assertOk()->json('data.0');

    expect($match['state'])->toBe('live')
        ->and($match['home']['score'])->toBe('148/3')
        ->and($match['home']['overs'])->toBe('15.2')
        ->and($match['home']['isBatting'])->toBeTrue()
        ->and($match['away']['score'])->toBeNull()
        ->and($match['away']['note'])->toBe('Yet to bat')
        ->and($match['away']['isBatting'])->toBeFalse()
        ->and($match['summary'])->toBe('Strong start')
        ->and($match['summaryTone'])->toBe('live')
        ->and($match['statusText'])->toBe('India chose to bat · 1st Innings');
});

it('joins both innings of a test match', function () {
    cricketFixture(['id' => 7, 'status' => 'Finished', 'starting_at' => now()->subDays(2), 'runs' => [
        ['team_id' => 40, 'inning' => 'S1', 'score' => 312, 'wickets' => 10, 'overs' => 90],
        ['team_id' => 40, 'inning' => 'S2', 'score' => 150, 'wickets' => 4, 'overs' => 30.1],
    ]]);

    $this->getJson('/api/v1/matches?state=result')->assertJsonPath('data.0.home.score', '312/10 & 150/4');
});

it('returns the match centre with scorecard, squads and stats', function () {
    cricketFixture(['id' => 20094, 'slug' => 'ind-vs-aus-20094', 'round' => '2nd T20I', ...liveScoreboard(), 'status' => '1st Innings', 'is_live' => true, 'starting_at' => now()->subHour()]);

    $detail = $this->getJson('/api/v1/matches/ind-vs-aus-20094')->assertOk()->json('data');

    expect($detail['title'])->toBe('India vs Australia')
        ->and($detail['subtitle'])->toBe('2nd T20I · Wankhede Stadium, Mumbai')
        ->and($detail['tossNote'])->toBe('India chose to bat')
        ->and($detail['overview']['currentRunRate'])->toBe('9.65')
        ->and($detail['overview']['atCrease'])->toBe([['name' => 'Rohit Sharma', 'runs' => 35, 'balls' => 20]])
        ->and($detail['innings'])->toHaveCount(1)
        ->and($detail['innings'][0]['team'])->toBe('India')
        ->and($detail['innings'][0]['rows'][0])->toBe(['batter' => 'Virat Kohli', 'dismissal' => 'c Steve Smith b Pat Cummins', 'runs' => 8, 'balls' => 10, 'fours' => 1, 'sixes' => 0, 'strikeRate' => '80.00'])
        ->and($detail['innings'][0]['rows'][1]['dismissal'])->toBe('batting')
        ->and($detail['innings'][0]['rows'][1]['strikeRate'])->toBe('175.00')
        ->and($detail['squads'])->toHaveCount(2)
        ->and($detail['squads'][0]['team']['name'])->toBe('India')
        ->and(collect($detail['squads'][0]['players'])->pluck('name')->all())->toBe(['Rohit Sharma', 'Virat Kohli'])
        ->and($detail['squads'][0]['players'][0])->toBe([
            'slug' => null,
            'name' => 'Rohit Sharma',
            'image' => 'https://cdn.sportmonks.com/p/11.png',
            'role' => 'Batsman',
            'captain' => true,
            'wicketkeeper' => false,
            'substitute' => false,
            'battingStyle' => 'Right hand bat',
            'bowlingStyle' => null,
        ])
        ->and($detail['stats'][0])->toBe(['label' => 'Runs', 'home' => '148', 'away' => null])
        ->and($detail['commentary'])->toBe([])
        ->and($detail['relatedNews'])->toBe([]);
});

it('links squad players to their profile and lists substitutes last', function () {
    Player::factory()->create(['id' => 11, 'slug' => 'rohit-sharma', 'fullname' => 'Rohit Sharma', 'team_id' => null]);
    cricketFixture(['id' => 8, 'slug' => 'm8', 'lineup' => [
        ['id' => 30, 'fullname' => 'Bench Player', 'position' => ['name' => 'Bowler'], 'bowlingstyle' => 'slow-left-arm-orthodox', 'lineup' => ['team_id' => 40, 'captain' => false, 'wicketkeeper' => false, 'substitution' => true]],
        ['id' => 11, 'fullname' => 'Rohit Sharma', 'position' => ['name' => 'Batsman'], 'lineup' => ['team_id' => 40, 'captain' => false, 'wicketkeeper' => true, 'substitution' => false]],
    ]]);

    $players = $this->getJson('/api/v1/matches/m8')->json('data.squads.0.players');

    expect(collect($players)->pluck('name')->all())->toBe(['Rohit Sharma', 'Bench Player'])
        ->and($players[0]['slug'])->toBe('rohit-sharma')
        ->and($players[0]['wicketkeeper'])->toBeTrue()
        ->and($players[1])->toMatchArray(['slug' => null, 'substitute' => true, 'role' => 'Bowler', 'bowlingStyle' => 'Slow left arm orthodox']);
});

it('falls back to the players table for names missing from the lineup', function () {
    Player::factory()->create(['id' => 12, 'fullname' => 'Virat Kohli', 'team_id' => null]);
    cricketFixture(['id' => 5, 'slug' => 'm5', 'status' => 'Finished', 'starting_at' => now()->subDay(), 'batting' => [
        ['sort' => 1, 'team_id' => 40, 'active' => false, 'scoreboard' => 'S1', 'player_id' => 12, 'ball' => 4, 'score' => 6, 'four_x' => 1, 'six_x' => 0, 'batsmanout_id' => null],
    ]]);

    $row = $this->getJson('/api/v1/matches/m5')->json('data.innings.0.rows.0');

    expect($row['batter'])->toBe('Virat Kohli')->and($row['dismissal'])->toBe('not out');
});

it('answers 404 as json for an unknown match', function () {
    $this->getJson('/api/v1/matches/nope')->assertNotFound()->assertJson(['message' => 'Resource not found.']);
});

it('summarises the matches of the day', function () {
    config(['sportmonks.provider_label' => 'TMN Sport Live']);
    cricketFixture(['id' => 1, 'slug' => 'a', 'starting_at' => now()])->forceFill(['status' => '2nd Innings', 'is_live' => true])->save();
    cricketFixture(['id' => 2, 'slug' => 'b', 'starting_at' => now()->addDays(5)]);

    $this->getJson('/api/v1/matches/summary')->assertOk()->assertExactJson([
        'matchesToday' => 1,
        'liveNow' => 1,
        'competitionsTracked' => 1,
        'dataProvider' => 'TMN Sport Live',
    ]);
});

it('groups the matchday by competition and honours the view and competition filter', function () {
    cricketFixture(['id' => 1, 'slug' => 'a', 'starting_at' => now()->setTimezone('Asia/Dhaka')->startOfDay()->addHours(12)]);
    $other = League::factory()->create(['name' => 'Another Cup', 'slug' => 'another-cup']);
    cricketFixture(['id' => 2, 'slug' => 'b', 'league_id' => $other->id, 'starting_at' => now()->setTimezone('Asia/Dhaka')->startOfDay()->addHours(14)]);
    cricketFixture(['id' => 3, 'slug' => 'c', 'starting_at' => now()->addDays(10)]);

    $all = $this->getJson('/api/v1/matchday?view=today')->assertOk()->json('data');

    expect($all['date'])->toBe(now('Asia/Dhaka')->toDateString())
        ->and(collect($all['groups'])->pluck('competition.name')->all())->toBe(['Another Cup', 'Test Series'])
        ->and(collect($all['competitions'])->pluck('slug')->all())->toBe(['another-cup', 'test-series'])
        ->and($all['groups'][1]['matches'][0]['slug'])->toBe('a');

    $filtered = $this->getJson('/api/v1/matchday?view=today&competition=another-cup')->json('data');
    expect($filtered['groups'])->toHaveCount(1)
        ->and($filtered['competitions'])->toHaveCount(2)
        ->and($this->getJson('/api/v1/matchday?view=week')->json('data.groups.*.matches.*.slug'))->not->toContain('c')
        ->and($this->getJson('/api/v1/matchday?view=upcoming')->json('data.groups.*.matches.*.slug'))->toContain('c');
});

it('lists competitions and the spotlight with status and date range', function () {
    cricketFixture(['id' => 1, 'slug' => 'a', 'starting_at' => now()->subDay()]);
    cricketFixture(['id' => 2, 'slug' => 'b', 'starting_at' => now()->addDays(4)]);

    $this->getJson('/api/v1/competitions?featured=1')->assertOk()->assertJsonPath('data.0.slug', 'test-series');

    $spotlight = $this->getJson('/api/v1/competitions/spotlight')->assertOk()->json('data.0');
    expect($spotlight)->toMatchArray(['slug' => 'test-series', 'format' => 'T20I', 'location' => 'International', 'status' => 'In progress', 'matchCount' => 2])
        ->and($spotlight['dateRange'])->toContain('–');
});

it('builds the series directory and series page with a points table', function () {
    cricketFixture(['id' => 1, 'slug' => 'a', 'starting_at' => now()->subDay()]);
    cricketFixture(['id' => 2, 'slug' => 'b', 'status' => 'Finished', 'starting_at' => now()->subDays(2)]);
    cricketFixture(['id' => 3, 'slug' => 'c', 'starting_at' => now()->addDays(2)]);
    Standing::factory()->create(['league_id' => 3, 'season_id' => 6, 'team_id' => 40, 'position' => 1, 'played' => 7, 'won' => 6, 'lost' => 1, 'points' => 12, 'net_run_rate' => 1.284, 'recent_form' => ['W', 'L', 'W']]);

    $directory = $this->getJson('/api/v1/series')->assertOk()->json('data');
    expect($directory['groups'])->toHaveCount(3)
        ->and($directory['items'][0])->toMatchArray(['slug' => 'test-series', 'status' => 'In progress', 'group' => 'current', 'description' => 'International T20I series']);

    $series = $this->getJson('/api/v1/series/test-series')->assertOk()->json('data');
    expect($series['status'])->toBe('In progress')
        ->and($series['pointsTable'])->toBe([['position' => 1, 'team' => 'India', 'played' => 7, 'won' => 6, 'lost' => 1, 'noResult' => 0, 'form' => ['W', 'L', 'W'], 'points' => 12, 'netRunRate' => '+1.284']])
        ->and($series['upcoming'])->toHaveCount(2)
        ->and($series['matches'])->toHaveCount(1)
        ->and($series['teams'])->toBe(['Australia', 'India'])
        ->and($series['dateline'])->toContain('International');
});

it('builds the teams list, directory, profile and fixtures', function () {
    cricketFixture(['id' => 1, 'slug' => 'a']);
    Team::factory()->create(['name' => 'Idle Team', 'slug' => 'idle-team']);
    Player::factory()->create(['team_id' => 40, 'fullname' => 'Rohit Sharma', 'slug' => 'rohit-sharma', 'position' => 'Batsman']);

    expect($this->getJson('/api/v1/teams')->json('data.*.slug'))->toBe(['australia', 'india']);

    $directory = $this->getJson('/api/v1/teams/directory')->json('data');
    expect($directory['items'])->toHaveCount(2)
        ->and($directory['items'][1])->toMatchArray(['slug' => 'india', 'badge' => 'IND', 'group' => 'international', 'description' => 'International cricket team']);

    $team = $this->getJson('/api/v1/teams/india')->assertOk()->json('data');
    expect($team['kind'])->toBe('International team')
        ->and($team['players'])->toBe([['slug' => 'rohit-sharma', 'name' => 'Rohit Sharma', 'role' => 'Batsman', 'image' => '']]);

    $fixtures = $this->getJson('/api/v1/teams/india/fixtures')->assertOk()->json('data');
    expect($fixtures['team']['homeGround'])->toBe('Wankhede Stadium')
        ->and($fixtures['team']['name'])->toBe('India')
        ->and($fixtures['matches'])->toHaveCount(1);

    $this->getJson('/api/v1/teams/unknown')->assertNotFound();
});

it('shows a player and fetches the career once from SportMonks', function () {
    config(['sportmonks.token' => 'secret-token-123']);
    $team = Team::factory()->create(['name' => 'India', 'slug' => 'india']);
    Player::factory()->create(['id' => 11, 'slug' => 'rohit-sharma', 'fullname' => 'Rohit Sharma', 'team_id' => $team->id, 'position' => 'Batsman', 'batting_style' => 'right-hand-bat', 'date_of_birth' => '1987-04-30']);
    Payloads::fake(['players/11' => ['data' => [
        'id' => 11, 'fullname' => 'Rohit Sharma', 'position' => ['name' => 'Batsman'],
        'career' => ['data' => [
            ['type' => 'T20I', 'season_id' => 1, 'batting' => ['matches' => 10, 'innings' => 10, 'not_outs' => 2, 'runs_scored' => 400, 'highest_inning_score' => 100, 'hundreds' => 1]],
            ['type' => 'T20I', 'season_id' => 2, 'batting' => ['matches' => 5, 'innings' => 5, 'not_outs' => 0, 'runs_scored' => 160, 'highest_inning_score' => 70, 'hundreds' => 0]],
        ]],
    ]]]);

    $page = $this->getJson('/api/v1/players/rohit-sharma')->assertOk()->json('data');
    $this->getJson('/api/v1/players/rohit-sharma')->assertOk();

    expect($page['name'])->toBe('Rohit Sharma')
        ->and($page['teamAndRole'])->toBe('India · Batsman')
        ->and($page['facts'])->toContain(['label' => 'Batting style', 'value' => 'Right hand bat'], ['label' => 'Born', 'value' => '30 April 1987'])
        ->and($page['career'])->toBe([['format' => 'T20I', 'matches' => 15, 'runs' => '560', 'average' => '43.08', 'hundreds' => 1, 'highScore' => '100']])
        ->and($page['news'])->toBe([]);

    Http::assertSentCount(1);
});

it('still shows a player when SportMonks is unreachable', function () {
    config(['sportmonks.token' => 'secret-token-123']);
    Player::factory()->create(['slug' => 'someone', 'fullname' => 'Some One', 'team_id' => null]);
    Http::fake(['*' => Http::response([], 500)]);

    $this->getJson('/api/v1/players/someone')->assertOk()->assertJsonPath('data.career', []);
});
