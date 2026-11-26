<?php

use App\Models\Article;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;

/**
 * @param  array<string, mixed>  $attributes
 */
function tickerFixture(array $attributes = []): Fixture
{
    $league = League::query()->first() ?? League::factory()->create();
    $india = Team::query()->find(40) ?? Team::factory()->national()->create(['id' => 40, 'name' => 'India', 'slug' => 'india', 'code' => 'IND']);
    $australia = Team::query()->find(41) ?? Team::factory()->national()->create(['id' => 41, 'name' => 'Australia', 'slug' => 'australia', 'code' => 'AUS']);

    return Fixture::factory()->create([
        'league_id' => $league->id,
        'local_team_id' => $india->id,
        'visitor_team_id' => $australia->id,
        ...$attributes,
    ]);
}

it('has nothing to show on an empty site, so the sample bar stays', function () {
    $this->getJson('/api/v1/ticker')->assertNotFound();
});

it('shows live matches, recent results and the next matches', function () {
    tickerFixture(['slug' => 'live-1', 'status' => '1st Innings', 'is_live' => true, 'runs' => [['team_id' => 40, 'inning' => 'S1', 'score' => 148, 'wickets' => 3, 'overs' => 15.2]]]);
    tickerFixture(['slug' => 'done-1', 'status' => 'Finished', 'starting_at' => now()->subDay(), 'note' => 'India won by 5 wickets']);
    tickerFixture(['slug' => 'old-1', 'status' => 'Finished', 'starting_at' => now()->subDays(10), 'note' => 'Too old']);
    tickerFixture(['slug' => 'next-1', 'status' => 'NS', 'starting_at' => now()->addHours(5)]);
    tickerFixture(['slug' => 'far-1', 'status' => 'NS', 'starting_at' => now()->addDays(9)]);

    $items = collect($this->getJson('/api/v1/ticker')->assertOk()->json('data'));

    expect($items->pluck('href')->all())->toBe(['/matches/live-1', '/matches/done-1', '/matches/next-1'])
        ->and($items[0]['text'])->toStartWith('LIVE · India 148/3 v Australia')
        ->and($items[1]['text'])->toContain('RESULT · ')->toContain('India won by 5 wickets')
        ->and($items[2]['text'])->toStartWith('NEXT · India v Australia');
});

it('puts breaking news before the matches', function () {
    tickerFixture(['slug' => 'live-1', 'status' => '1st Innings', 'is_live' => true]);
    Article::factory()->create(['title' => 'Breaking story', 'slug' => 'breaking', 'is_breaking' => true]);

    $this->getJson('/api/v1/ticker')->assertOk()
        ->assertJsonPath('data.0.text', 'Breaking story')
        ->assertJsonPath('data.1.href', '/matches/live-1');
});
