<?php

use App\Models\Article;
use App\Models\Player;
use App\Models\Team;

/**
 * One season of batting in a format, as SportMonks stores it.
 *
 * @return array<string, mixed>
 */
function trendingCareerRow(string $format, int $runs, int $matches = 10, int $innings = 10, int $notOuts = 0): array
{
    return [
        'type' => $format,
        'batting' => ['matches' => $matches, 'innings' => $innings, 'not_outs' => $notOuts, 'runs_scored' => $runs],
    ];
}

/**
 * A player the list may show: with a photo and one-day figures, unless told otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function listedPlayer(string $name, int $runs = 100, array $attributes = []): Player
{
    $slug = str($name)->slug()->value();

    return Player::factory()->create([
        'fullname' => $name,
        'slug' => $slug,
        'image' => "https://cdn.sportmonks.com/images/cricket/players/1/{$slug}.png",
        'career' => [trendingCareerRow('ODI', $runs)],
        ...$attributes,
    ]);
}

it('returns the card in the website contract shape', function () {
    $pakistan = Team::factory()->national()->create(['name' => 'Pakistan']);
    listedPlayer('Babar Azam', attributes: [
        'id' => 8,
        'team_id' => $pakistan->id,
        'career' => [
            trendingCareerRow('ODI', 300, matches: 6, innings: 6, notOuts: 1),
            trendingCareerRow('ODI', 201, matches: 5, innings: 5),
            trendingCareerRow('T20I', 999),
        ],
    ]);

    $this->getJson('/api/v1/players/trending')
        ->assertOk()
        ->assertExactJson(['data' => [[
            'id' => 8,
            'slug' => 'babar-azam',
            'name' => 'Babar Azam',
            'image' => 'https://cdn.sportmonks.com/images/cricket/players/1/babar-azam.png',
            'role' => 'Batsman',
            'team' => '',
            'country' => 'Pakistan',
            'rankLabel' => 'ODI',
            'matches' => 11,
            'runs' => 501,
            'average' => 50.1,
        ]]]);
});

it('lists players named in recent stories first and fills the other places by runs', function () {
    listedPlayer('Top Scorer', runs: 9000);
    listedPlayer('Second Scorer', runs: 8000);
    listedPlayer('Third Scorer', runs: 7000);
    listedPlayer('Fourth Scorer', runs: 6000);
    listedPlayer('Once Named', runs: 20);
    listedPlayer('Twice Named', runs: 10);
    Article::factory()->create(['title' => 'Twice Named leads the chase', 'summary' => 'Once Named adds a fifty']);
    Article::factory()->create(['body' => 'A calm hand from TWICE NAMED, once again.']);

    $this->getJson('/api/v1/players/trending')
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Twice Named', 'Once Named', 'Top Scorer', 'Second Scorer']);
});

it('does not count a story that visitors cannot read or that is over a month old', function (Closure $story) {
    listedPlayer('Top Scorer', runs: 9000);
    listedPlayer('Quiet Player', runs: 10);
    $story();

    $this->getJson('/api/v1/players/trending')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Top Scorer');
})->with([
    'a draft' => [fn () => fn () => Article::factory()->draft()->create(['title' => 'Quiet Player returns'])],
    'an old story' => [fn () => fn () => Article::factory()->create(['title' => 'Quiet Player returns', 'published_at' => now()->subDays(31)])],
]);

it('does not take part of a longer word for a player name', function () {
    listedPlayer('Top Scorer', runs: 9000);
    listedPlayer('Sam Cur', runs: 10);
    Article::factory()->create(['title' => 'Sam Curran takes five']);

    $this->getJson('/api/v1/players/trending')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Top Scorer');
});

it('leaves out a player without a real photo', function (?string $image) {
    listedPlayer('No Photo', attributes: ['image' => $image]);

    $this->getJson('/api/v1/players/trending')
        ->assertOk()
        ->assertExactJson(['data' => []]);
})->with([
    'nothing stored' => [null],
    'an empty address' => [''],
    'the SportMonks placeholder' => ['https://cdn.sportmonks.com/images/cricket/placeholder.png'],
]);

it('shows T20I figures when a player has no one-day runs', function () {
    listedPlayer('Short Format', attributes: ['career' => [trendingCareerRow('T20I', 450, matches: 12, innings: 12, notOuts: 3)]]);

    $this->getJson('/api/v1/players/trending')
        ->assertOk()
        ->assertJsonPath('data.0.rankLabel', 'T20I')
        ->assertJsonPath('data.0.matches', 12)
        ->assertJsonPath('data.0.runs', 450)
        ->assertJsonPath('data.0.average', 50);
});

it('leaves out a player without one-day or T20I runs', function (?array $career) {
    listedPlayer('No Figures', attributes: ['career' => $career]);

    $this->getJson('/api/v1/players/trending')
        ->assertOk()
        ->assertExactJson(['data' => []]);
})->with([
    'no career yet' => [null],
    'domestic matches only' => [[trendingCareerRow('T20', 800)]],
]);

it('names a franchise as the team rather than the country', function () {
    $franchise = Team::factory()->create(['name' => 'Peshawar Zalmi']);
    listedPlayer('Franchise Player', attributes: ['team_id' => $franchise->id]);

    $this->getJson('/api/v1/players/trending')
        ->assertOk()
        ->assertJsonPath('data.0.team', 'Peshawar Zalmi')
        ->assertJsonPath('data.0.country', '');
});
