<?php

use App\Models\Article;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Http;

describe('the players list', function () {
    it('lists every player with the photographed ones first, then by name', function () {
        $pakistan = Team::factory()->national()->create(['name' => 'Pakistan']);
        $photo = 'https://cdn.sportmonks.com/images/cricket/players/1/1.png';
        Player::factory()->create(['fullname' => 'Aaron Blank', 'slug' => 'aaron-blank', 'image' => 'https://cdn.sportmonks.com/images/cricket/placeholder.png']);
        Player::factory()->create(['fullname' => 'Abel Nothing', 'slug' => 'abel-nothing', 'image' => null]);
        Player::factory()->create(['id' => 7, 'fullname' => 'Zed Pictured', 'slug' => 'zed-pictured', 'image' => $photo, 'position' => 'Bowler', 'team_id' => $pakistan->id]);

        $this->getJson('/api/v1/players')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.lastPage', 1)
            ->assertJsonPath('data.players.*.name', ['Zed Pictured', 'Aaron Blank', 'Abel Nothing'])
            ->assertJsonPath('data.players.0', ['id' => 7, 'slug' => 'zed-pictured', 'name' => 'Zed Pictured', 'image' => $photo, 'role' => 'Bowler', 'team' => '', 'country' => 'Pakistan'])
            ->assertJsonPath('data.players.2.image', '');
    });

    it('returns the requested page', function () {
        Player::factory()->count(3)->sequence(['fullname' => 'Alpha'], ['fullname' => 'Bravo'], ['fullname' => 'Charlie'])->create();

        $this->getJson('/api/v1/players?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.page', 2)
            ->assertJsonPath('data.lastPage', 2)
            ->assertJsonPath('data.players.*.name', ['Charlie']);
    });

    it('finds players by part of their name', function () {
        Player::factory()->create(['fullname' => 'Babar Azam']);
        Player::factory()->create(['fullname' => 'Virat Kohli']);

        $this->getJson('/api/v1/players?search=%20aza%20')
            ->assertOk()
            ->assertJsonPath('data.players.*.name', ['Babar Azam']);
    });

    it('filters by role', function (string $role, array $names) {
        Player::factory()->count(5)->sequence(
            ['fullname' => 'Opener', 'position' => 'Top Order Batter'],
            ['fullname' => 'Batter', 'position' => 'Batsman'],
            ['fullname' => 'Seamer', 'position' => 'Bowler'],
            ['fullname' => 'Both', 'position' => 'Bowling Allrounder'],
            ['fullname' => 'Keeper', 'position' => 'Wicketkeeper'],
        )->create();

        $this->getJson("/api/v1/players?role={$role}")
            ->assertOk()
            ->assertJsonPath('data.players.*.name', $names);
    })->with([
        'batters' => ['batter', ['Batter', 'Opener']],
        'bowlers' => ['bowler', ['Seamer']],
        'all-rounders' => ['allrounder', ['Both']],
        'wicketkeepers' => ['wicketkeeper', ['Keeper']],
    ]);

    it('returns an empty page when nobody matches', function () {
        $this->getJson('/api/v1/players?search=nobody')
            ->assertOk()
            ->assertJsonPath('data.players', [])
            ->assertJsonPath('data.total', 0);
    });
});

describe('a player profile', function () {
    it('adds up batting and bowling by format, in page order', function () {
        $season = fn (string $type, array $batting, ?array $bowling): array => ['type' => $type, 'batting' => $batting, 'bowling' => $bowling];
        Player::factory()->create(['slug' => 'all-round', 'career' => [
            $season('T20', ['matches' => 1, 'innings' => 1, 'runs_scored' => 10, 'balls_faced' => 8], null),
            $season('Test/5day', ['matches' => 2, 'innings' => 3, 'not_outs' => 1, 'runs_scored' => 90, 'balls_faced' => 180, 'fifties' => 1, 'four_x' => 9, 'six_x' => 2], ['matches' => 2, 'innings' => 2, 'overs' => 3.4, 'runs' => 20, 'wickets' => 1]),
            $season('Test/5day', ['matches' => 1, 'innings' => 1, 'runs_scored' => 10, 'balls_faced' => 20], ['matches' => 1, 'innings' => 1, 'overs' => 2.5, 'runs' => 19, 'wickets' => 2, 'four_wickets' => 1]),
        ]]);

        $this->getJson('/api/v1/players/all-round')
            ->assertOk()
            ->assertJsonPath('data.career.*.format', ['Test', 'T20'])
            ->assertJsonPath('data.career.0.runs', '100')
            ->assertJsonPath('data.career.0.average', '33.33')
            ->assertJsonPath('data.career.0.strikeRate', '50.00')
            ->assertJsonPath('data.career.0.fifties', 1)
            ->assertJsonPath('data.bowling', [[
                'format' => 'Test', 'matches' => 3, 'innings' => 3, 'overs' => '6.3', 'runs' => '39', 'wickets' => 3,
                'average' => '13.00', 'economy' => '6.00', 'strikeRate' => '13.00', 'fourWickets' => 1, 'fiveWickets' => 0,
            ]]);
    });

    it('gives the age and gender among the facts', function () {
        $this->travelTo('2026-06-01');
        Player::factory()->create(['slug' => 'dated', 'date_of_birth' => '1996-05-31', 'gender' => 'f']);

        $facts = $this->getJson('/api/v1/players/dated')->assertOk()->json('data.facts');

        expect($facts)->toContain(['label' => 'Age', 'value' => '30'], ['label' => 'Gender', 'value' => 'Female']);
    });

    it('lists the three latest stories a visitor can read that name the player', function () {
        Player::factory()->create(['slug' => 'babar-azam', 'fullname' => 'Babar Azam']);
        Article::factory()->create(['title' => 'Babar Azam first', 'published_at' => now()->subDays(4)]);
        Article::factory()->create(['title' => 'Second', 'summary' => 'A day for Babar Azam', 'published_at' => now()->subDays(3)]);
        Article::factory()->create(['title' => 'Third', 'body' => 'Then Babar Azam walked in.', 'published_at' => now()->subDays(2)]);
        Article::factory()->create(['title' => 'Babar Azam fourth', 'published_at' => now()->subDay()]);
        Article::factory()->draft()->create(['title' => 'Babar Azam unpublished']);
        Article::factory()->create(['title' => 'Someone else entirely']);

        $this->getJson('/api/v1/players/babar-azam')
            ->assertOk()
            ->assertJsonPath('data.news.*.title', ['Babar Azam fourth', 'Third', 'Second']);
    });

    it('shows stored figures at once and renews week-old ones after the response', function () {
        config(['sportmonks.token' => 'secret-token-123']);
        $player = Player::factory()->create(['id' => 11, 'slug' => 'stale', 'details_synced_at' => now()->subWeeks(2), 'career' => []]);
        Http::fake(['*' => Http::response(['data' => ['id' => 11, 'fullname' => $player->fullname, 'career' => ['data' => [
            ['type' => 'ODI', 'batting' => ['matches' => 1, 'innings' => 1, 'runs_scored' => 50]],
        ]]]])]);

        $this->getJson('/api/v1/players/stale')->assertOk()->assertJsonPath('data.career', []);

        Http::assertSentCount(1);
        expect($player->refresh()->career)->toHaveCount(1);
    });

    it('returns 404 for a player that does not exist', function () {
        $this->getJson('/api/v1/players/nobody')->assertNotFound();
    });
});
