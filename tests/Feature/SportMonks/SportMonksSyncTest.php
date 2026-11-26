<?php

use App\Enums\SyncTarget;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\Season;
use App\Models\Standing;
use App\Models\SyncRun;
use App\Models\Team;
use App\Models\TeamRanking;
use App\Services\SportMonks\SportMonksSyncer;
use Illuminate\Support\Facades\Http;
use Tests\Support\SportMonksPayloads as Payloads;

beforeEach(function () {
    config(['sportmonks.token' => 'secret-token-123']);
});

it('stores leagues with their seasons and marks the current one', function () {
    Payloads::fake(['leagues' => ['data' => [Payloads::league()]]]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::LEAGUES);

    expect($run->status)->toBe(SyncRun::SUCCESS)->and($run->records_synced)->toBe(1);

    $league = League::query()->findOrFail(3);
    expect($league->slug)->toBe('test-series')
        ->and($league->current_season_id)->toBe(6)
        ->and(Season::query()->count())->toBe(2)
        ->and(Season::query()->findOrFail(6)->is_current)->toBeTrue()
        ->and(Season::query()->findOrFail(5)->is_current)->toBeFalse();
});

it('keeps what the editor arranged when the leagues are synced again', function () {
    League::factory()->create(['id' => 3, 'name' => 'Old Name', 'slug' => 'test-series', 'is_featured' => false, 'sort_order' => 7]);
    Payloads::fake(['leagues' => ['data' => [Payloads::league()]]]);

    app(SportMonksSyncer::class)->run(SyncTarget::LEAGUES);

    $league = League::query()->findOrFail(3);
    expect($league->name)->not->toBe('Old Name')
        ->and($league->is_featured)->toBeFalse()
        ->and($league->sort_order)->toBe(7);
});

it('only syncs the leagues listed in SPORTMONKS_LEAGUE_IDS', function () {
    config(['sportmonks.league_ids' => [9]]);
    Payloads::fake(['leagues' => ['data' => [Payloads::league(3), Payloads::league(9, 'Chosen Cup', 20)]]]);

    app(SportMonksSyncer::class)->run(SyncTarget::LEAGUES);

    expect(League::query()->pluck('id')->all())->toBe([9]);
});

it('stores fixtures with embedded teams and venue and does not duplicate them', function () {
    Payloads::fake(['fixtures' => ['data' => [Payloads::fixture()], 'meta' => ['pagination' => ['total_pages' => 1]]]]);

    $syncer = app(SportMonksSyncer::class);
    $syncer->run(SyncTarget::FIXTURES);
    $second = $syncer->run(SyncTarget::FIXTURES);

    expect($second->status)->toBe(SyncRun::SUCCESS)
        ->and(Fixture::query()->count())->toBe(1)
        ->and(Team::query()->count())->toBe(2);

    $fixture = Fixture::query()->findOrFail(20094);
    expect($fixture->slug)->toBe('ind-vs-aus-20094')
        ->and($fixture->venue_id)->toBe(96)
        ->and($fixture->status)->toBe('NS')
        ->and(League::query()->count())->toBe(0);

    Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'filter[starts_between]=')
        && str_contains($request->url(), 'include=localteam%2Cvisitorteam%2Cvenue%2Cruns'));
});

it('keeps the match slug stable when a team is renamed', function () {
    Payloads::fake(['fixtures' => Http::sequence()
        ->push(['data' => [Payloads::fixture()]])
        ->push(['data' => [Payloads::fixture(['localteam' => ['data' => Payloads::team(40, 'Team India', 'TI')]])]])]);
    $syncer = app(SportMonksSyncer::class);

    $syncer->run(SyncTarget::FIXTURES);
    $syncer->run(SyncTarget::FIXTURES);

    expect(Fixture::query()->findOrFail(20094)->slug)->toBe('ind-vs-aus-20094')
        ->and(Team::query()->findOrFail(40)->name)->toBe('Team India');
});

it('tags a league as international when both sides are national teams', function () {
    League::factory()->create(['id' => 3, 'format' => null, 'category' => null]);
    Payloads::fake(['fixtures' => ['data' => [Payloads::fixture()]]]);

    app(SportMonksSyncer::class)->run(SyncTarget::FIXTURES);

    expect(League::query()->findOrFail(3)->only(['format', 'category']))->toBe(['format' => 'T20I', 'category' => 'International']);
});

it('updates a live match with scoreboards and creates players from the lineup', function () {
    Fixture::factory()->live()->create(['id' => 20094, 'league_id' => League::factory()->create(['id' => 3])->id]);
    Payloads::fake(['livescores' => ['data' => [Payloads::liveFixture()]]]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::LIVE);

    expect($run->records_synced)->toBe(1);

    $fixture = Fixture::query()->findOrFail(20094);
    expect($fixture->is_live)->toBeTrue()
        ->and($fixture->runs[0]['score'])->toBe(148)
        ->and($fixture->batting)->toHaveCount(2)
        ->and($fixture->lineup)->toHaveCount(4)
        ->and(Player::query()->findOrFail(11)->fullname)->toBe('Rohit Sharma')
        ->and(Player::query()->findOrFail(11)->team_id)->toBe(40)
        ->and(Player::query()->findOrFail(11)->position)->toBe('Batsman');
});

it('makes no livescores call while no match is scheduled around now', function () {
    Fixture::factory()->create(['starting_at' => now()->addDays(3)]);
    Http::fake();

    $run = app(SportMonksSyncer::class)->run(SyncTarget::LIVE);

    expect($run->status)->toBe(SyncRun::SUCCESS)->and($run->records_synced)->toBe(0);
    Http::assertNothingSent();
});

it('leaves no sync_runs row while nothing is live, so a 10 second schedule does not fill the table', function () {
    Fixture::factory()->create(['starting_at' => now()->addDays(3)]);
    Http::fake();

    foreach (range(1, 5) as $unused) {
        app(SportMonksSyncer::class)->run(SyncTarget::LIVE);
    }

    expect(SyncRun::query()->count())->toBe(0);
});

it('polls live scores for the configured window after a match was due to start', function () {
    Fixture::factory()->create(['starting_at' => now()->subHours(8)]);
    Payloads::fake(['livescores' => ['data' => []]]);

    config(['sportmonks.live_window_hours' => 6]);
    app(SportMonksSyncer::class)->run(SyncTarget::LIVE);
    Http::assertNothingSent();

    config(['sportmonks.live_window_hours' => 12]);
    app(SportMonksSyncer::class)->run(SyncTarget::LIVE);
    Http::assertSentCount(1);
});

it('keeps only a day of live runs', function () {
    SyncRun::factory()->create(['target' => 'live', 'started_at' => now()->subDays(2)]);
    SyncRun::factory()->create(['target' => 'live', 'started_at' => now()->subMinutes(5)]);
    SyncRun::factory()->create(['target' => 'fixtures', 'started_at' => now()->subDays(2)]);

    expect(app(SportMonksSyncer::class)->pruneLiveRuns())->toBe(1)
        ->and(SyncRun::query()->count())->toBe(2);
});

it('fetches the scorecard of a finished match once and marks it done', function () {
    $league = League::factory()->create(['id' => 3]);
    Fixture::factory()->finished()->create(['id' => 20094, 'league_id' => $league->id]);
    Payloads::fake(['fixtures/20094' => ['data' => Payloads::liveFixture([
        'status' => 'Finished',
        'live' => false,
        'winner_team_id' => 40,
        'note' => 'India won by 6 wickets',
        'starting_at' => now()->subDay()->toIso8601String(),
    ])]]);

    $syncer = app(SportMonksSyncer::class);
    $first = $syncer->run(SyncTarget::DETAILS);
    $second = $syncer->run(SyncTarget::DETAILS);

    $fixture = Fixture::query()->findOrFail(20094);
    expect($first->records_synced)->toBe(1)
        ->and($second->records_synced)->toBe(0)
        ->and($fixture->details_synced_at)->not->toBeNull()
        ->and($fixture->winner_team_id)->toBe(40)
        ->and($fixture->batting)->toHaveCount(2);

    Http::assertSentCount(1);
});

it('refreshes a match that dropped out of the live feed without a final status', function () {
    $league = League::factory()->create(['id' => 3]);
    $stale = Fixture::factory()->live()->create(['id' => 20094, 'league_id' => $league->id]);
    $stale->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();
    Payloads::fake(['fixtures/20094' => ['data' => Payloads::fixture(['status' => 'Finished', 'starting_at' => now()->subHours(5)->toIso8601String()])]]);

    app(SportMonksSyncer::class)->run(SyncTarget::DETAILS);

    expect(Fixture::query()->findOrFail(20094)->only(['status', 'is_live']))->toBe(['status' => 'Finished', 'is_live' => false]);
});

it('stores league standings for the current season', function () {
    $league = League::factory()->create(['id' => 3, 'current_season_id' => 6]);
    Team::factory()->create(['id' => 40]);
    Team::factory()->create(['id' => 41]);
    Payloads::fake(['standings/season/6' => ['data' => [
        ['team_id' => 40, 'stage_id' => 1, 'season_id' => 6, 'league_id' => 3, 'position' => 1, 'points' => 12, 'played' => 7, 'won' => 6, 'lost' => 1, 'draw' => 0, 'noresult' => 0, 'netto_run_rate' => 1.284, 'recent_form' => ['W', 'W']],
        ['team_id' => 41, 'stage_id' => 1, 'season_id' => 6, 'league_id' => 3, 'position' => 2, 'points' => 10, 'played' => 7, 'won' => 5, 'lost' => 2, 'draw' => 0, 'noresult' => 0, 'netto_run_rate' => 0.5, 'recent_form' => ['L']],
        ['team_id' => 999, 'season_id' => 6, 'position' => 3],
    ]]]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::STANDINGS);

    expect($run->records_synced)->toBe(2)
        ->and(Standing::query()->where('team_id', 40)->firstOrFail()->net_run_rate)->toBe(1.284)
        ->and($league->standings()->count())->toBe(2);
});

it('stores squads for the teams playing soon', function () {
    Fixture::factory()->create(['season_id' => 6, 'local_team_id' => Team::factory()->create(['id' => 40])->id, 'visitor_team_id' => Team::factory()->create(['id' => 41])->id]);
    Payloads::fake([
        'teams/40/squad/6' => ['data' => ['id' => 40, 'squad' => [['id' => 11, 'fullname' => 'Rohit Sharma', 'position' => ['name' => 'Batsman']]]]],
        'teams/41/squad/6' => ['data' => ['id' => 41, 'squad' => ['data' => [['id' => 21, 'firstname' => 'Pat', 'lastname' => 'Cummins']]]]],
    ]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::SQUADS);

    expect($run->records_synced)->toBe(2)
        ->and(Player::query()->findOrFail(11)->team_id)->toBe(40)
        ->and(Player::query()->findOrFail(21)->fullname)->toBe('Pat Cummins');
});

it('fills in the squad of a national team that has no match in the window', function () {
    Team::factory()->national()->create(['id' => 1, 'name' => 'Pakistan', 'slug' => 'pakistan']);
    Team::factory()->create(['id' => 9, 'national_team' => false]);
    Payloads::fake([
        'teams/1' => ['data' => ['id' => 1, 'squad' => ['data' => [['id' => 51, 'fullname' => 'Babar Azam', 'position' => ['name' => 'Batsman']]]]]],
        'fixtures' => ['data' => []],
    ]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::SQUADS);

    expect($run->records_synced)->toBe(1)
        ->and(Player::query()->findOrFail(51)->team_id)->toBe(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'teams/1') && str_contains($request->url(), 'include=squad'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'teams/9'));
});

it('stores the official team rankings for every format and gender', function () {
    $team = fn (int $id, string $name, int $position, int $matches, int $points, int $rating): array => [
        ...Payloads::team($id, $name, strtoupper(substr($name, 0, 3))),
        'position' => $position,
        'ranking' => ['position' => $position, 'matches' => $matches, 'points' => $points, 'rating' => $rating],
    ];

    Payloads::fake([
        'team-rankings' => ['data' => [
            ['resource' => 'team-rankings', 'type' => 'TEST', 'gender' => 'men', 'team' => [$team(40, 'India', 1, 30, 3600, 120), $team(41, 'Australia', 2, 28, 3220, 115)]],
            ['resource' => 'team-rankings', 'type' => 'ODI', 'gender' => 'men', 'team' => ['data' => [$team(40, 'India', 2, 40, 4640, 116)]]],
            ['resource' => 'team-rankings', 'type' => 'T20I', 'gender' => 'women', 'team' => [$team(41, 'Australia', 1, 20, 2900, 145)]],
            ['resource' => 'team-rankings', 'type' => 'WTEST', 'gender' => 'men', 'team' => [$team(40, 'India', 1, 1, 1, 1)]],
        ]],
    ]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::RANKINGS);

    expect($run->status)->toBe(SyncRun::SUCCESS)
        ->and($run->records_synced)->toBe(4)
        ->and(TeamRanking::query()->count())->toBe(4)
        ->and(TeamRanking::query()->where(['team_id' => 41, 'format' => 'TEST', 'gender' => 'men'])->firstOrFail()->points)->toBe(3220)
        ->and(TeamRanking::query()->where(['team_id' => 41, 'format' => 'T20I', 'gender' => 'women'])->firstOrFail()->position)->toBe(1)
        ->and(Team::query()->find(40)->name)->toBe('India');

    app(SportMonksSyncer::class)->run(SyncTarget::RANKINGS);

    expect(TeamRanking::query()->count())->toBe(4);
});

it('fetches career statistics for players of ranked teams only, oldest first', function () {
    $india = Team::factory()->national()->create(['id' => 40]);
    $club = Team::factory()->create(['id' => 90]);
    TeamRanking::factory()->create(['team_id' => 40]);
    $never = Player::factory()->create(['id' => 1, 'team_id' => 40, 'details_synced_at' => null]);
    $stale = Player::factory()->create(['id' => 2, 'team_id' => 40, 'details_synced_at' => now()->subWeeks(3)]);
    $fresh = Player::factory()->create(['id' => 3, 'team_id' => 40, 'details_synced_at' => now()->subHour()]);
    $other = Player::factory()->create(['id' => 4, 'team_id' => 90, 'details_synced_at' => null]);
    config(['sportmonks.sync.careers_per_run' => 2]);

    Payloads::fake([
        'players/1' => ['data' => ['id' => 1, 'fullname' => $never->fullname, 'career' => [['type' => 'ODI', 'batting' => ['runs_scored' => 100]]]]],
        'players/2' => ['data' => ['id' => 2, 'fullname' => $stale->fullname, 'career' => []]],
    ]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::CAREERS);

    expect($run->records_synced)->toBe(2)
        ->and($never->refresh()->career[0]['batting']['runs_scored'])->toBe(100)
        ->and($fresh->refresh()->details_synced_at->lt(now()->subMinutes(30)))->toBeTrue()
        ->and($other->refresh()->details_synced_at)->toBeNull();
});

it('records a failed run with a readable message when the token is rejected', function () {
    Payloads::fake(['leagues' => Http::response(['message' => 'Unauthenticated'], 401)]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::LEAGUES);

    expect($run->status)->toBe(SyncRun::FAILED)
        ->and($run->message)->toContain('rejected the API token')
        ->and($run->message)->not->toContain('secret-token-123');
});

it('stops a full sync after a fatal error instead of repeating it', function () {
    Payloads::fake(['*' => Http::response([], 401)]);

    $runs = app(SportMonksSyncer::class)->runAll();

    expect($runs)->toHaveCount(1)->and($runs[0]->status)->toBe(SyncRun::FAILED);
    Http::assertSentCount(1);
});

it('runs every target of a full sync in dependency order', function () {
    Payloads::fake([
        'leagues' => ['data' => [Payloads::league()]],
        'venues' => ['data' => [['id' => 96, 'name' => 'Wankhede Stadium', 'city' => 'Mumbai']]],
        'teams' => ['data' => [Payloads::team(40, 'India', 'IND'), Payloads::team(41, 'Australia', 'AUS')]],
        'fixtures' => ['data' => [Payloads::fixture(['starting_at' => now()->addDay()->toIso8601String()])]],
        'standings/season/6' => ['data' => []],
        'teams/*' => ['data' => ['id' => 40, 'squad' => []]],
        'team-rankings' => ['data' => []],
    ]);

    $runs = app(SportMonksSyncer::class)->runAll();

    expect(collect($runs)->pluck('target')->all())->toBe(['leagues', 'venues', 'teams', 'fixtures', 'squads', 'standings', 'details', 'rankings'])
        ->and(collect($runs)->pluck('status')->unique()->all())->toBe([SyncRun::SUCCESS]);
});

it('exposes an artisan command that explains a missing token', function () {
    config(['sportmonks.token' => null]);

    $this->artisan('sportmonks:sync', ['target' => 'all'])
        ->expectsOutputToContain('SPORTMONKS_API_TOKEN')
        ->assertFailed();
});

it('runs a single target from the artisan command', function () {
    Payloads::fake(['leagues' => ['data' => [Payloads::league()]]]);

    $this->artisan('sportmonks:sync', ['target' => 'leagues'])
        ->expectsOutputToContain('1 records')
        ->assertSuccessful();

    expect(League::query()->count())->toBe(1);
});

it('rejects an unknown sync target', function () {
    $this->artisan('sportmonks:sync', ['target' => 'nonsense'])->assertExitCode(2);
});

it('notes when players last played, from stored line-ups and from the recent matches of idle national teams', function () {
    Team::factory()->national()->create(['id' => 1, 'name' => 'Pakistan', 'slug' => 'pakistan']);
    Team::factory()->national()->create(['id' => 40, 'name' => 'India', 'slug' => 'india']);
    Team::factory()->create(['id' => 90, 'national_team' => false]);
    $stored = Player::factory()->create(['id' => 11, 'team_id' => 90]);
    $idle = Player::factory()->create(['id' => 51, 'team_id' => 1]);
    Fixture::factory()->create(['local_team_id' => 40, 'visitor_team_id' => 90, 'starting_at' => now()->subMonths(3), 'lineup' => [['id' => 11, 'lineup' => ['team_id' => 40]]]]);

    Payloads::fake([
        'teams/*' => ['data' => ['squad' => []]],
        'fixtures' => ['data' => [[
            'id' => 700,
            'starting_at' => now()->subMonths(5)->toIso8601String(),
            'lineup' => ['data' => [
                ['id' => 51, 'fullname' => $idle->fullname, 'lineup' => ['team_id' => 1]],
                ['id' => 52, 'fullname' => 'Shaheen Afridi', 'lineup' => ['team_id' => 1]],
                ['id' => 60, 'fullname' => 'Opponent Player', 'lineup' => ['team_id' => 40]],
            ]],
        ]]],
    ]);

    $run = app(SportMonksSyncer::class)->run(SyncTarget::SQUADS);

    expect($run->status)->toBe(SyncRun::SUCCESS)
        ->and($stored->refresh()->team_id)->toBe(40)
        ->and($stored->last_played_at->isSameDay(now()->subMonths(3)))->toBeTrue()
        ->and($idle->refresh()->last_played_at->isSameDay(now()->subMonths(5)))->toBeTrue()
        ->and(Player::query()->findOrFail(52)->team_id)->toBe(1)
        ->and(Player::query()->find(60))->toBeNull()
        ->and(Fixture::query()->find(700))->toBeNull();
});
