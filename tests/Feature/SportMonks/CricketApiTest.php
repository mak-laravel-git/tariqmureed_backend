<?php

use App\Models\Article;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamRanking;
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

it('sends each team logo so the site can draw it where there is no country flag', function () {
    cricketFixture(['id' => 1, 'slug' => 'ind-vs-aus-1']);
    Team::query()->find(40)->forceFill(['image' => 'https://cdn.sportmonks.com/images/cricket/teams/8/40.png'])->save();

    $this->getJson('/api/v1/matches?state=upcoming')
        ->assertOk()
        ->assertJsonPath('data.0.home.team.image', 'https://cdn.sportmonks.com/images/cricket/teams/8/40.png')
        ->assertJsonPath('data.0.away.team.image', null);

    $this->getJson('/api/v1/teams')->assertOk()->assertJsonFragment(['slug' => 'india', 'image' => 'https://cdn.sportmonks.com/images/cricket/teams/8/40.png']);
});

it('shows a team profile with its match record', function () {
    cricketFixture(['id' => 1, 'slug' => 'a', 'status' => 'Finished', 'starting_at' => now()->subDays(3), 'winner_team_id' => 40]);
    cricketFixture(['id' => 2, 'slug' => 'b', 'status' => 'Finished', 'starting_at' => now()->subDays(2), 'winner_team_id' => 41]);
    cricketFixture(['id' => 3, 'slug' => 'c', 'status' => 'Finished', 'starting_at' => now()->subDay(), 'winner_team_id' => null]);
    cricketFixture(['id' => 4, 'slug' => 'd']);

    $this->getJson('/api/v1/teams/india')
        ->assertOk()
        ->assertJsonPath('data.ranks.0', ['label' => 'Matches played', 'value' => '3'])
        ->assertJsonPath('data.ranks.1', ['label' => 'Won', 'value' => '1'])
        ->assertJsonPath('data.ranks.2', ['label' => 'Lost', 'value' => '1'])
        ->assertJsonPath('data.ranks.3', ['label' => 'No result', 'value' => '1']);

    Team::factory()->national()->create(['id' => 77, 'name' => 'Nepal', 'slug' => 'nepal']);
    $this->getJson('/api/v1/teams/nepal')->assertOk()->assertJsonPath('data.ranks', [])->assertJsonPath('data.players', []);
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
        ->and($detail['overview']['atCrease'])->toBe([['name' => 'Rohit Sharma', 'runs' => 35, 'balls' => 20, 'fours' => 4, 'sixes' => 1]])
        ->and($detail['overview']['projectedScore'])->toBe('193')
        ->and($detail['overview']['bowler'])->toBe(['name' => 'Pat Cummins', 'figures' => '1/24 (3 ov)'])
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
        ->and(array_column($series['matches'], 'slug'))->toBe(['a', 'c', 'b'])
        ->and($series['teams'])->toBe(['Australia', 'India'])
        ->and($series['dateline'])->toContain('International');
});

it('lists the matches in play first on the series matches tab', function () {
    cricketFixture(['id' => 1, 'slug' => 'old-result', 'status' => 'Finished', 'starting_at' => now()->subDays(9)]);
    cricketFixture(['id' => 2, 'slug' => 'new-result', 'status' => 'Finished', 'starting_at' => now()->subDays(2)]);
    cricketFixture(['id' => 3, 'slug' => 'next', 'starting_at' => now()->addDays(2)]);
    cricketFixture(['id' => 4, 'slug' => 'stumps', 'starting_at' => now()->subDays(2)])->forceFill(['status' => 'Stump Day 2'])->save();

    expect($this->getJson('/api/v1/series/test-series')->assertOk()->json('data.matches.*.slug'))
        ->toBe(['stumps', 'next', 'new-result', 'old-result']);
});

it('keeps a match that began on an earlier day in today and this week while it is in play', function () {
    cricketFixture(['id' => 1, 'slug' => 'day-three', 'starting_at' => now()->subDays(2)])->forceFill(['status' => 'Stump Day 2'])->save();
    cricketFixture(['id' => 2, 'slug' => 'over', 'status' => 'Finished', 'starting_at' => now()->subDays(2)]);
    $other = League::factory()->create(['name' => 'Another Cup', 'slug' => 'another-cup']);

    foreach (['today', 'week'] as $view) {
        expect($this->getJson("/api/v1/matchday?view={$view}")->assertOk()->json('data.groups.*.matches.*.slug'))->toBe(['day-three']);
    }

    expect($this->getJson('/api/v1/matchday?view=tomorrow')->json('data.groups'))->toBe([])
        ->and($this->getJson("/api/v1/matchday?view=today&competition={$other->slug}")->json('data.groups'))->toBe([]);
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

it('serves the official rankings table by gender and format', function () {
    $this->getJson('/api/v1/rankings')->assertOk()->assertJsonPath('data.tables', []);

    $india = Team::factory()->national()->create(['name' => 'India', 'slug' => 'india', 'code' => 'IND']);
    $australia = Team::factory()->national()->create(['name' => 'Australia', 'slug' => 'australia', 'code' => 'AUS']);
    TeamRanking::factory()->create(['team_id' => $australia->id, 'format' => 'TEST', 'gender' => 'men', 'position' => 2, 'rating' => 110]);
    TeamRanking::factory()->create(['team_id' => $india->id, 'format' => 'TEST', 'gender' => 'men', 'position' => 1, 'rating' => 120]);
    TeamRanking::factory()->create(['team_id' => $india->id, 'format' => 'ODI', 'gender' => 'women', 'position' => 3]);

    $data = $this->getJson('/api/v1/rankings')->assertOk()
        ->assertJsonPath('data.formats.0', ['key' => 'TEST', 'label' => 'Test'])
        ->assertJsonPath('data.tables.men.TEST.0.team.slug', 'india')
        ->assertJsonPath('data.tables.men.TEST.0.position', 1)
        ->assertJsonPath('data.tables.men.TEST.1.team.slug', 'australia')
        ->assertJsonPath('data.tables.women.ODI.0.position', 3)
        ->json('data');

    expect($data['tables']['men'])->not->toHaveKey('ODI')
        ->and($data['updatedAt'])->toContain('T');
});

it('ranks a team\'s players by career runs and wickets and shows the team\'s place in each table', function () {
    $team = Team::factory()->national()->create(['name' => 'India', 'slug' => 'india']);
    TeamRanking::factory()->create(['team_id' => $team->id, 'format' => 'TEST', 'gender' => 'men', 'position' => 3]);
    TeamRanking::factory()->create(['team_id' => $team->id, 'format' => 'ODI', 'gender' => 'men', 'position' => 1]);

    $career = fn (string $type, int $runs, int $innings, int $notOuts, int $wickets = 0, int $conceded = 0): array => [
        'type' => $type,
        'batting' => ['matches' => 10, 'innings' => $innings, 'not_outs' => $notOuts, 'runs_scored' => $runs, 'hundreds' => 2, 'highest_inning_score' => 120],
        'bowling' => ['matches' => 10, 'wickets' => $wickets, 'runs' => $conceded, 'five_wickets' => 1],
    ];

    Player::factory()->create(['team_id' => $team->id, 'fullname' => 'Top Batter', 'slug' => 'top-batter', 'career' => [$career('ODI', 1000, 22, 2), $career('ODI', 500, 10, 0), $career('TEST', 300, 6, 0)]]);
    Player::factory()->create(['team_id' => $team->id, 'fullname' => 'Second Batter', 'slug' => 'second-batter', 'career' => [$career('ODI', 800, 20, 0)]]);
    Player::factory()->create(['team_id' => $team->id, 'fullname' => 'Strike Bowler', 'slug' => 'strike-bowler', 'career' => [$career('ODI', 0, 0, 0, 50, 1500)]]);
    Player::factory()->create(['team_id' => $team->id, 'fullname' => 'No Data', 'slug' => 'no-data', 'career' => null]);

    $data = $this->getJson('/api/v1/teams/india')->assertOk()
        ->assertJsonPath('data.ranks.0', ['label' => 'Test ranking', 'value' => '#3'])
        ->assertJsonPath('data.ranks.1', ['label' => 'ODI ranking', 'value' => '#1'])
        ->json('data.playerRankings');

    expect(collect($data['formats'])->pluck('key')->all())->toBe(['TEST', 'ODI'])
        ->and(collect($data['batting']['ODI'])->pluck('slug')->all())->toBe(['top-batter', 'second-batter'])
        ->and($data['batting']['ODI'][0])->toMatchArray(['rank' => 1, 'runs' => 1500, 'matches' => 20, 'average' => 50.0, 'hundreds' => 4])
        ->and($data['batting']['ODI'][1]['rank'])->toBe(2)
        ->and(collect($data['bowling']['ODI'])->pluck('slug')->all())->toBe(['strike-bowler'])
        ->and($data['bowling']['ODI'][0])->toMatchArray(['wickets' => 50, 'average' => 30.0])
        ->and($data['batting']['TEST'][0]['slug'])->toBe('top-batter');
});

it('shows the players who played recently, most recent first, and leaves retired ones out', function () {
    $team = Team::factory()->national()->create(['name' => 'Pakistan', 'slug' => 'pakistan']);
    $player = fn (string $slug, array $attributes) => Player::factory()->create(['team_id' => $team->id, 'slug' => $slug, 'fullname' => ucfirst($slug), ...$attributes]);

    $player('abdul', ['date_of_birth' => now()->subYears(47), 'career' => [['type' => 'ODI', 'batting' => ['runs_scored' => 5000, 'innings' => 100]]]]);
    $player('babar', ['last_played_at' => now()->subMonths(2), 'career' => [['type' => 'ODI', 'batting' => ['runs_scored' => 900, 'innings' => 20]]]]);
    $player('zaman', ['last_played_at' => now()->subWeek()]);
    $player('longago', ['last_played_at' => now()->subYears(3)]);

    $data = $this->getJson('/api/v1/teams/pakistan')->assertOk()->json('data');

    expect(collect($data['players'])->pluck('slug')->all())->toBe(['zaman', 'babar'])
        ->and(collect($data['playerRankings']['batting']['ODI'])->pluck('slug')->all())->toBe(['babar']);
});

it('falls back to players young enough to be active when a team has no match on record', function () {
    $team = Team::factory()->national()->create(['name' => 'Nepal', 'slug' => 'nepal']);
    Player::factory()->create(['team_id' => $team->id, 'slug' => 'veteran', 'fullname' => 'A Veteran', 'date_of_birth' => now()->subYears(45)]);
    Player::factory()->create(['team_id' => $team->id, 'slug' => 'youngster', 'fullname' => 'B Youngster', 'date_of_birth' => now()->subYears(22)]);
    Player::factory()->create(['team_id' => $team->id, 'slug' => 'unknown-age', 'fullname' => 'C Unknown', 'date_of_birth' => null]);

    $data = $this->getJson('/api/v1/teams/nepal')->assertOk()->json('data');

    expect(collect($data['players'])->pluck('slug')->all())->toBe(['youngster', 'unknown-age']);
});

/**
 * A feed delivery of the India innings.
 *
 * @param  array<string, mixed>  $score
 * @return array<string, mixed>
 */
function delivery(int $id, float $over, array $score, string $board = 'S1', int $team = 40): array
{
    return [
        'id' => $id, 'scoreboard' => $board, 'ball' => $over, 'team_id' => $team,
        'batsman' => ['fullname' => 'Rohit Sharma'], 'bowler' => ['fullname' => 'Pat Cummins'],
        'score' => ['name' => 'No Run', 'runs' => 0, 'bye' => 0, 'leg_bye' => 0, 'noball' => 0, 'noball_runs' => 0, 'four' => false, 'six' => false, 'is_wicket' => false, 'ball' => true, 'out' => false, ...$score],
    ];
}

it('fills every tab of a finished match from one feed call, fetched when the page is first opened', function () {
    config(['sportmonks.token' => 'secret-token-123']);
    cricketFixture(['id' => 500, 'slug' => 'done', ...liveScoreboard(), 'status' => 'Finished', 'is_live' => false, 'starting_at' => now()->subDay(), 'note' => 'India won by 5 runs', 'type' => 'T20I']);
    Player::factory()->create(['id' => 11, 'slug' => 'rohit-sharma', 'fullname' => 'Rohit Sharma', 'team_id' => 40]);
    Article::factory()->create(['title' => 'India edge Australia in a thriller', 'slug' => 'both', 'published_at' => now()->subDays(2)]);
    Article::factory()->create(['title' => 'Australia name new captain', 'slug' => 'one', 'published_at' => now()->subHour()]);
    Article::factory()->create(['title' => 'Unrelated county news', 'slug' => 'none', 'summary' => 'County cricket', 'tags' => 'county']);

    Payloads::fake(['fixtures/500' => ['data' => Payloads::liveFixture([
        'id' => 500, 'status' => 'Finished', 'live' => false, 'note' => 'India won by 5 runs', 'total_overs_played' => 20,
        'balls' => ['data' => [
            delivery(1, 0.1, ['name' => 'FOUR', 'runs' => 4, 'four' => true]),
            delivery(2, 0.2, ['name' => '1 Wide', 'runs' => 1, 'ball' => false]),
            delivery(3, 0.2, ['name' => 'Catch Out', 'is_wicket' => true, 'out' => true]),
            delivery(4, 0.3, ['name' => 'No Run']),
        ]],
        'scoreboards' => ['data' => [
            ['team_id' => 40, 'scoreboard' => 'S1', 'type' => 'total', 'total' => 52, 'wickets' => 1, 'overs' => 6.2],
            ['team_id' => 40, 'scoreboard' => 'S1', 'type' => 'extra', 'wide' => 5, 'noball_balls' => 1, 'noball_runs' => 0, 'bye' => 2, 'leg_bye' => 1, 'penalty' => 0],
        ]],
        'manofmatch' => ['data' => ['id' => 11, 'fullname' => 'Rohit Sharma']],
        'firstumpire' => ['data' => ['id' => 1, 'fullname' => 'Donovan Koch']],
        'referee' => ['data' => ['id' => 2, 'fullname' => 'Javagal Srinath']],
        'stage' => ['data' => ['name' => 'Australia tour of India']],
        'season' => ['data' => ['name' => '2026']],
    ])]]);

    $detail = $this->getJson('/api/v1/matches/done')->assertOk()->json('data');
    $this->getJson('/api/v1/matches/done')->assertOk();

    Http::assertSentCount(1);

    expect($detail['overview']['result'])->toBe('India won by 5 runs')
        ->and($detail['overview']['atCrease'])->toBe([])
        ->and($detail['overview']['playerOfMatch'])->toMatchArray(['name' => 'Rohit Sharma', 'slug' => 'rohit-sharma'])
        ->and($detail['overview']['officials'])->toBe([['role' => 'Umpire', 'name' => 'Donovan Koch'], ['role' => 'Match referee', 'name' => 'Javagal Srinath']])
        ->and($detail['overview']['series'])->toBe('Australia tour of India · 2026')
        ->and($detail['overview']['performers'][0]['batter'])->toBe('Rohit Sharma 35 (20)')
        ->and($detail['overview']['performers'][0]['bowler'])->toBe('Pat Cummins 1/24 (3 ov)');

    $innings = $detail['innings'][0];

    expect($innings['total'])->toBe(['runs' => 52, 'wickets' => 1, 'overs' => '6.2', 'runRate' => '8.21'])
        ->and($innings['extras'])->toBe(['total' => 9, 'wides' => 5, 'noBalls' => 1, 'byes' => 2, 'legByes' => 1, 'penalty' => 0])
        ->and($innings['bowling'][0])->toMatchArray(['bowler' => 'Pat Cummins', 'overs' => '3', 'runs' => 24, 'wickets' => 1, 'economy' => '8.00'])
        ->and($innings['yetToBat'])->toBe([]);

    expect(collect($detail['commentary'])->pluck('outcome')->all())->toBe(['•', 'W', 'Wd', '4'])
        ->and($detail['commentary'][3])->toMatchArray(['over' => '0.1', 'innings' => 'India innings', 'text' => 'Pat Cummins to Rohit Sharma, FOUR'])
        ->and($detail['commentary'][1]['text'])->toBe('Pat Cummins to Rohit Sharma, OUT! Catch Out');

    $stats = collect($detail['stats'])->keyBy('label');

    expect($stats['Dot balls faced']['home'])->toBe('2')
        ->and($stats['Top score']['home'])->toBe('Rohit Sharma 35')
        ->and($stats['Best bowling']['away'])->toBe('Pat Cummins 1/24')
        ->and(collect($detail['relatedNews'])->pluck('slug')->all())->toBe(['both', 'one']);
});

it('shows the chase and the current partnership while a match is on, refreshing at most every half minute', function () {
    config(['sportmonks.token' => 'secret-token-123']);
    cricketFixture(['id' => 501, 'slug' => 'chase', 'status' => '2nd Innings', 'is_live' => true, 'starting_at' => now()->subHours(2), 'type' => 'T20I']);
    Payloads::fake(['fixtures/501' => ['data' => Payloads::liveFixture([
        'id' => 501, 'status' => '2nd Innings',
        'runs' => [
            ['team_id' => 41, 'inning' => 1, 'score' => 160, 'wickets' => 6, 'overs' => 20],
            ['team_id' => 40, 'inning' => 2, 'score' => 101, 'wickets' => 2, 'overs' => 12],
        ],
        'batting' => [['id' => 9, 'sort' => 1, 'team_id' => 40, 'active' => true, 'scoreboard' => 'S2', 'player_id' => 11, 'ball' => 30, 'score' => 55, 'four_x' => 5, 'six_x' => 2]],
        'balls' => ['data' => [
            delivery(1, 11.4, ['name' => 'Catch Out', 'is_wicket' => true, 'out' => true], 'S2'),
            delivery(2, 11.5, ['name' => 'SIX', 'runs' => 6, 'six' => true], 'S2'),
            delivery(3, 11.6, ['name' => '4 Leg Byes', 'leg_bye' => 4], 'S2'),
        ]],
    ])]]);

    $overview = $this->getJson('/api/v1/matches/chase')->assertOk()->json('data.overview');
    $this->getJson('/api/v1/matches/chase')->assertOk();

    Http::assertSentCount(1);

    expect($overview['target'])->toBe('161')
        ->and($overview['requiredRunRate'])->toBe('7.50')
        ->and($overview['currentRunRate'])->toBe('8.42')
        ->and($overview['partnership'])->toBe('10 (2 balls)')
        ->and($overview['recentBalls'])->toBe(['W', '6', '4'])
        ->and($overview['projectedScore'])->toBeNull();

    $this->travel(31)->seconds();
    $this->getJson('/api/v1/matches/chase')->assertOk();

    Http::assertSentCount(2);
});

it('keeps showing stored data when the feed cannot be reached, and asks nothing for far-off matches', function () {
    config(['sportmonks.token' => 'secret-token-123']);
    cricketFixture(['id' => 502, 'slug' => 'later', 'starting_at' => now()->addDays(3)]);
    cricketFixture(['id' => 503, 'slug' => 'broken', ...liveScoreboard(), 'status' => 'Finished', 'starting_at' => now()->subDay()]);
    Payloads::fake(['*' => Http::response([], 500)]);

    $this->getJson('/api/v1/matches/later')->assertOk();
    Http::assertNothingSent();

    $this->getJson('/api/v1/matches/broken')->assertOk()->assertJsonPath('data.innings.0.team', 'India');
});

it('leaves out boundary figures when the feed sends only the totals', function () {
    cricketFixture(['id' => 504, 'slug' => 'totals-only', 'status' => 'Finished', 'starting_at' => now()->subDay(), 'runs' => [
        ['team_id' => 40, 'inning' => 1, 'score' => 313, 'wickets' => 6, 'overs' => 48.4],
        ['team_id' => 41, 'inning' => 2, 'score' => 309, 'wickets' => 10, 'overs' => 49.2],
    ]]);

    $detail = $this->getJson('/api/v1/matches/totals-only')->assertOk()->json('data');

    expect(collect($detail['stats'])->pluck('label')->all())->toBe(['Runs', 'Wickets lost', 'Overs faced', 'Run rate'])
        ->and($detail['innings'])->toBe([])
        ->and($detail['squads'])->toBe([]);
});

it('sends league logos, and no picture for a placeholder side', function () {
    cricketFixture(['id' => 600, 'slug' => 'final', 'starting_at' => now()->addDays(2)]);
    League::query()->find(3)->forceFill(['image' => 'https://cdn.sportmonks.com/images/cricket/leagues/3/3.png', 'is_featured' => true])->save();
    Team::query()->find(41)->forceFill(['name' => 'TBC', 'image' => 'https://cdn.sportmonks.com'])->save();

    $this->getJson('/api/v1/series')->assertOk()->assertJsonPath('data.items.0.image', 'https://cdn.sportmonks.com/images/cricket/leagues/3/3.png');
    $this->getJson('/api/v1/series/test-series')->assertOk()
        ->assertJsonPath('data.image', 'https://cdn.sportmonks.com/images/cricket/leagues/3/3.png')
        ->assertJsonPath('data.teams', ['India']);
    $this->getJson('/api/v1/matches/final')->assertOk()->assertJsonPath('data.away.team.image', null);
});
